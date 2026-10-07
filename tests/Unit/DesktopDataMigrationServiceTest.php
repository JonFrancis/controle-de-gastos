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
        $source = new PDO('sqlite:'.config('desktop.source_database_path'));
        $source->exec('INSERT INTO purchases (id) VALUES (7)');

        $result = app(DesktopDataMigrationService::class)->migrate();

        $this->assertSame('imported', $result['status']);
        $this->assertFileExists($result['original_backup']);
        $this->assertFileExists(config('desktop.target_database_path'));
        $this->assertFileExists(config('desktop.migration_marker_path'));
        $this->assertSame(1, (int) (new PDO('sqlite:'.config('desktop.target_database_path')))->query('SELECT COUNT(*) FROM purchases')->fetchColumn());
        $this->assertSame(1, (int) (new PDO('sqlite:'.$result['original_backup']))->query('SELECT COUNT(*) FROM purchases')->fetchColumn());
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

    public function test_unreadable_database_reports_an_actionable_permission_error(): void
    {
        $this->createDatabase(['migrations', 'app_settings']);
        File::partialMock()->shouldReceive('isReadable')->once()->andReturnFalse();

        $this->expectExceptionMessage('não pode ser lido');
        app(DesktopDataMigrationService::class)->migrate();
    }

    public function test_existing_target_blocks_a_second_import_without_overwriting_it(): void
    {
        $this->createDatabase(['migrations', 'app_settings']);
        File::put(config('desktop.target_database_path'), 'existing data');

        $this->expectExceptionMessage('já existe');
        app(DesktopDataMigrationService::class)->migrate();
    }

    public function test_update_backup_preserves_the_current_database(): void
    {
        $target = new PDO('sqlite:'.config('desktop.target_database_path'));
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE purchases (id INTEGER PRIMARY KEY)');
        $target->exec('INSERT INTO purchases (id) VALUES (9)');

        $backup = app(DesktopDataMigrationService::class)->createUpdateBackup();

        $this->assertFileExists($backup);
        $this->assertSame(1, (int) (new PDO('sqlite:'.$backup))->query('SELECT COUNT(*) FROM purchases')->fetchColumn());
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
