<?php

namespace Tests\Unit;

use App\Exceptions\DesktopDataMigrationException;
use App\Services\DesktopDataMigrationService;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class DesktopDataMigrationServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'\\controle-de-gastos-migration-'.uniqid('', true);
        File::ensureDirectoryExists($this->directory);
        config([
            'desktop.source_database_path' => $this->directory.'\\source.sqlite',
            'desktop.target_database_path' => $this->directory.'\\target.sqlite',
            'desktop.migration_marker_path' => $this->directory.'\\marker.json',
            'desktop.migration_backup_path' => $this->directory.'\\backups',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_migration_preserves_the_original_and_imports_the_database(): void
    {
        $this->createDatabase(['migrations', 'app_settings', 'purchases']);

        $result = app(DesktopDataMigrationService::class)->migrate();

        $this->assertSame('imported', $result['status']);
        $this->assertFileExists($result['original_backup']);
        $this->assertFileExists(config('desktop.target_database_path'));
        $this->assertFileExists(config('desktop.migration_marker_path'));
    }

    public function test_second_migration_is_idempotent(): void
    {
        $this->createDatabase(['migrations', 'app_settings']);
        $service = app(DesktopDataMigrationService::class);
        $first = $service->migrate();

        $this->assertSame('already_imported', $service->migrate()['status']);
        $this->assertSame($first['original_backup'], $service->migrate()['original_backup']);
    }

    public function test_incompatible_database_is_rejected_without_creating_a_target(): void
    {
        $this->createDatabase(['migrations']);

        $this->expectException(DesktopDataMigrationException::class);
        try {
            app(DesktopDataMigrationService::class)->migrate();
        } finally {
            $this->assertFileDoesNotExist(config('desktop.target_database_path'));
            $this->assertDirectoryDoesNotExist(config('desktop.migration_backup_path'));
        }
    }

    public function test_existing_target_blocks_a_second_import_without_overwriting_it(): void
    {
        $this->createDatabase(['migrations', 'app_settings']);
        File::put(config('desktop.target_database_path'), 'existing data');

        $this->expectExceptionMessage('já existe');
        app(DesktopDataMigrationService::class)->migrate();
    }

    /** @param list<string> $tables */
    private function createDatabase(array $tables): void
    {
        $pdo = new PDO('sqlite:'.config('desktop.source_database_path'));
        foreach ($tables as $table) {
            $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
        }
    }
}
