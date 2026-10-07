<?php

namespace App\Services;

use App\Exceptions\InvalidBackupException;
use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use JsonException;

class BackupService
{
    public const FORMAT = 'controle-de-gastos-backup';

    public const VERSION = 1;

    /** @return array{path: string, filename: string} */
    public function create(?string $directory = null, string $source = 'manual'): array
    {
        $directory = $this->ensureDirectory($directory ?? ($source === 'automatic'
            ? $this->automaticDirectory()
            : $this->configuredDirectory()));
        $filename = 'controle-de-gastos-backup-'.($source === 'automatic' ? 'automatic-' : '').now()->format('Ymd_His_u').'.json';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $tables = [];

        foreach ($this->tableNames() as $table) {
            $tables[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
        }

        $payload = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'source' => $source,
            'tables' => $tables,
        ];

        try {
            $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidBackupException('Não foi possível serializar o backup.', previous: $exception);
        }

        $temporaryPath = $path.'.tmp';
        if (File::put($temporaryPath, $contents) === false || ! File::move($temporaryPath, $path)) {
            throw new InvalidBackupException('Não foi possível salvar o backup no local configurado.');
        }

        AppSetting::query()->first()?->update([
            'last_backup_path' => $path,
            'last_backup_at' => now(),
        ]);

        if ($source === 'automatic') {
            $this->pruneAutomaticBackups($directory);
        }

        return ['path' => $path, 'filename' => $filename];
    }

    /** @return array{path: string, filename: string} */
    public function restore(UploadedFile $file): array
    {
        $payload = $this->readAndValidate($file->getRealPath() ?: '');
        $previous = $this->create(source: 'pre-restore');

        Schema::withoutForeignKeyConstraints(function () use ($payload): void {
            DB::transaction(function () use ($payload): void {
                foreach ($this->tableNames() as $table) {
                    DB::table($table)->delete();
                }

                foreach ($payload['tables'] as $table => $rows) {
                    if ($rows !== []) {
                        DB::table($table)->insert($rows);
                    }
                }
            });
        });

        return $previous;
    }

    /** @return array{path: string, filename: string}|null */
    public function createAutomaticIfEnabled(): ?array
    {
        $setting = AppSetting::query()->first();

        if (! $setting?->automatic_backup_enabled) {
            return null;
        }

        return $this->createAutomaticIfNeeded();
    }

    /** @return array{path: string, filename: string}|null */
    public function createAutomaticIfNeeded(): ?array
    {
        $setting = AppSetting::query()->first();
        if (! $setting?->automatic_backup_enabled || ! $this->hasChangesSinceLastBackup($setting->last_backup_at)) {
            return null;
        }

        return $this->create(source: 'automatic');
    }

    public function hasChangesSinceLastBackup(?\DateTimeInterface $lastBackupAt = null): bool
    {
        $lastBackupAt ??= AppSetting::query()->value('last_backup_at');
        $lastBackupAt = $lastBackupAt instanceof \DateTimeInterface
            ? $lastBackupAt
            : ($lastBackupAt !== null ? new \DateTimeImmutable((string) $lastBackupAt) : null);

        foreach ($this->tableNames() as $table) {
            if (in_array($table, ['app_settings', 'migrations'], true)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (! in_array('created_at', $columns, true) && ! in_array('updated_at', $columns, true)) {
                continue;
            }

            $query = DB::table($table);
            if ($lastBackupAt !== null) {
                $query->where(function ($query) use ($columns, $lastBackupAt): void {
                    if (in_array('created_at', $columns, true)) {
                        $query->where('created_at', '>', $lastBackupAt);
                    }
                    if (in_array('updated_at', $columns, true)) {
                        $query->orWhere('updated_at', '>', $lastBackupAt);
                    }
                });
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function defaultDirectory(): string
    {
        if ($configured = AppSetting::query()->value('backup_path')) {
            return (string) $configured;
        }

        $documents = PHP_OS_FAMILY === 'Windows'
            ? (string) (getenv('USERPROFILE') ?: storage_path('app'))
            : (string) (getenv('HOME') ?: storage_path('app'));

        return rtrim($documents, '\\/').DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'Controle de Gastos'.DIRECTORY_SEPARATOR.'Backups';
    }

    /** @return array<string, mixed> */
    private function readAndValidate(string $path): array
    {
        if ($path === '' || ! File::isFile($path) || ! File::isReadable($path)) {
            throw new InvalidBackupException('Selecione um arquivo de backup válido.');
        }

        try {
            $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidBackupException('O arquivo de backup não contém JSON válido.');
        }

        $requiredKeys = ['format', 'version', 'generated_at', 'source', 'tables'];
        if (! is_array($payload)
            || array_diff($requiredKeys, array_keys($payload)) !== []
            || array_diff(array_keys($payload), $requiredKeys) !== []
            || ($payload['format'] ?? null) !== self::FORMAT
            || ($payload['version'] ?? null) !== self::VERSION
            || ! is_string($payload['generated_at'] ?? null)
            || ! is_string($payload['source'] ?? null)
            || ! is_array($payload['tables'] ?? null)) {
            throw new InvalidBackupException('O arquivo não é um backup compatível com esta versão.');
        }

        $knownTables = $this->tableNames();
        $tables = $payload['tables'];
        if (array_diff($knownTables, array_keys($tables)) !== [] || array_diff(array_keys($tables), $knownTables) !== []) {
            throw new InvalidBackupException('O backup não contém exatamente todas as tabelas esperadas.');
        }

        foreach ($tables as $table => $rows) {
            if (! is_string($table) || ! is_array($rows)) {
                throw new InvalidBackupException('O backup contém uma tabela desconhecida ou inválida.');
            }

            $columns = Schema::getColumnListing($table);
            foreach ($rows as $row) {
                if (! is_array($row) || array_diff($columns, array_keys($row)) !== [] || array_diff(array_keys($row), $columns) !== []) {
                    throw new InvalidBackupException('O backup contém colunas incompatíveis.');
                }
            }
        }

        return $payload;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        return array_values(array_filter(
            Schema::getTableListing(null, false),
            fn (string $table): bool => $table !== 'sqlite_sequence',
        ));
    }

    private function configuredDirectory(): string
    {
        return $this->defaultDirectory();
    }

    private function automaticDirectory(): string
    {
        return $this->defaultDirectory();
    }

    private function pruneAutomaticBackups(string $directory): void
    {
        $files = collect(File::glob($directory.DIRECTORY_SEPARATOR.'controle-de-gastos-backup-automatic-*.json'))
            ->sortByDesc(fn (string $path): int => (int) @filemtime($path));

        $files->slice(30)->each(fn (string $path): bool => File::delete($path));
    }

    private function ensureDirectory(string $directory): string
    {
        if (! File::isDirectory($directory) && ! File::makeDirectory($directory, 0755, true)) {
            throw new InvalidBackupException('Não foi possível criar o local configurado para backups.');
        }

        return rtrim($directory, '\\/');
    }
}
