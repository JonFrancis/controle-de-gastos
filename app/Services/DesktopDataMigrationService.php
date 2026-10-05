<?php

namespace App\Services;

use App\Exceptions\DesktopDataMigrationException;
use Illuminate\Support\Facades\File;
use PDO;

class DesktopDataMigrationService
{
    /** @return array{status: string, source: string, target: string, original_backup: string|null} */
    public function migrate(): array
    {
        $source = $this->sourcePath();
        $target = $this->targetPath();

        if (! File::isFile($source)) {
            return ['status' => 'source_missing', 'source' => $source, 'target' => $target, 'original_backup' => null];
        }

        if (File::isFile($this->markerPath())) {
            $marker = json_decode(File::get($this->markerPath()), true);
            if (! File::isFile($target)) {
                throw new DesktopDataMigrationException('O marcador de migração existe, mas o banco do aplicativo não foi encontrado. Restaure uma cópia antes de continuar.');
            }

            return [
                'status' => 'already_imported',
                'source' => $source,
                'target' => $target,
                'original_backup' => is_array($marker) ? ($marker['original_backup'] ?? null) : null,
            ];
        }

        $this->validateSource($source);
        if (File::isFile($target)) {
            if (! $this->isEmptyNativeDatabase($target)) {
                throw new DesktopDataMigrationException('O banco local do aplicativo já existe. A importação foi bloqueada para evitar duplicação.');
            }

            $this->backupSource($target);
            File::delete($target);
        }

        $backup = $this->backupSource($source);
        File::ensureDirectoryExists(dirname($target));
        $temporaryTarget = $target.'.tmp';
        if (! File::copy($source, $temporaryTarget) || ! File::move($temporaryTarget, $target)) {
            throw new DesktopDataMigrationException('Não foi possível copiar o banco para o local do aplicativo.');
        }

        File::ensureDirectoryExists(dirname($this->markerPath()));
        File::put($this->markerPath(), json_encode([
            'source' => $source,
            'target' => $target,
            'original_backup' => $backup,
            'migrated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['status' => 'imported', 'source' => $source, 'target' => $target, 'original_backup' => $backup];
    }

    public function createUpdateBackup(): string
    {
        $source = $this->targetPath();
        if (! File::isFile($source)) {
            throw new DesktopDataMigrationException('O banco do aplicativo não foi encontrado para o backup da atualização.');
        }

        return $this->backupSource($source);
    }

    private function sourcePath(): string
    {
        return (string) config('desktop.source_database_path');
    }

    private function targetPath(): string
    {
        if (config('nativephp-internal.running') && config('nativephp-internal.database_path')) {
            return (string) config('nativephp-internal.database_path');
        }

        return (string) config('desktop.target_database_path');
    }

    private function markerPath(): string
    {
        return (string) config('desktop.migration_marker_path');
    }

    private function validateSource(string $source): void
    {
        if (! File::isReadable($source)) {
            throw new DesktopDataMigrationException('O banco local existe, mas não pode ser lido. Verifique as permissões.');
        }

        try {
            $pdo = new PDO('sqlite:'.$source);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $exception) {
            throw new DesktopDataMigrationException('O banco local não é um SQLite válido.', previous: $exception);
        }

        if (! in_array('migrations', $tables, true) || ! in_array('app_settings', $tables, true)) {
            throw new DesktopDataMigrationException('O banco local não é compatível com esta versão do Controle de Gastos.');
        }
    }

    private function backupSource(string $source): string
    {
        $directory = config('desktop.migration_backup_path') ?: dirname($source).DIRECTORY_SEPARATOR.'desktop-migration-backups';
        File::ensureDirectoryExists($directory);
        $backup = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'database-before-migration-'.now()->format('Ymd_His_u').'.sqlite';
        if (! File::copy($source, $backup)) {
            throw new DesktopDataMigrationException('Não foi possível preservar uma cópia do banco original.');
        }

        return $backup;
    }

    private function isEmptyNativeDatabase(string $path): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$path);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $domainTables = array_diff($tables, ['migrations', 'app_settings']);

            foreach ($domainTables as $table) {
                if ((int) $pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn() > 0) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
