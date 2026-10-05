<?php

namespace Tests\Unit;

use App\Contracts\AvailabilityChecker;
use App\Contracts\ProcessExecutor;
use App\Exceptions\InvalidBackupException;
use App\Services\ApplicationLifecycleService;
use App\Services\BackupService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ApplicationLifecycleServiceTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = storage_path('framework/testing/lifecycle-'.md5($this->name()).'.json');
        File::delete($this->statePath);
        config([
            'lifecycle.state_path' => $this->statePath,
            'lifecycle.host' => '127.0.0.1',
            'lifecycle.port' => 8000,
            'lifecycle.startup_timeout_seconds' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->statePath);
        Mockery::close();
        parent::tearDown();
    }

    public function test_existing_active_instance_is_reused_without_starting_another_process(): void
    {
        File::ensureDirectoryExists(dirname($this->statePath));
        File::put($this->statePath, json_encode(['pid' => 1234, 'token' => 'owned']));

        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('isRunning')->with(1234)->andReturnTrue();
        $processes->expects('start')->never();
        $availability = Mockery::mock(AvailabilityChecker::class);
        $availability->expects('responds')->with('http://127.0.0.1:8000')->andReturnTrue();

        $service = new ApplicationLifecycleService($processes, $availability, Mockery::mock(BackupService::class));

        $this->assertSame('http://127.0.0.1:8000', $service->ensureStarted());
    }

    public function test_already_listening_port_is_reused_even_without_an_owned_state_file(): void
    {
        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('start')->never();
        $availability = Mockery::mock(AvailabilityChecker::class);
        $availability->expects('responds')->with('http://127.0.0.1:8000')->once()->andReturnTrue();

        $service = new ApplicationLifecycleService($processes, $availability, Mockery::mock(BackupService::class));

        $this->assertSame('http://127.0.0.1:8000', $service->ensureStarted());
    }

    public function test_start_waits_until_the_application_responds(): void
    {
        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('start')->with(base_path(), '127.0.0.1', 8000)->andReturn(4321);
        $availability = Mockery::mock(AvailabilityChecker::class);
        $availability->expects('responds')->with('http://127.0.0.1:8000')->twice()->andReturn(false, true);

        $service = new ApplicationLifecycleService($processes, $availability, Mockery::mock(BackupService::class));

        $this->assertSame('http://127.0.0.1:8000', $service->ensureStarted());
    }

    public function test_start_failure_terminates_the_process_and_clears_ownership_state(): void
    {
        config(['lifecycle.startup_timeout_seconds' => 0]);
        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('start')->andReturn(9876);
        $processes->expects('isRunning')->with(9876)->andReturnTrue();
        $processes->expects('terminate')->with(9876);
        $availability = Mockery::mock(AvailabilityChecker::class);
        $availability->expects('responds')->with('http://127.0.0.1:8000')->once()->andReturnFalse();

        $service = new ApplicationLifecycleService($processes, $availability, Mockery::mock(BackupService::class));

        $this->expectExceptionMessage('não respondeu');
        try {
            $service->ensureStarted();
        } finally {
            $this->assertFalse(File::exists($this->statePath));
        }
    }

    public function test_shutdown_creates_the_final_backup_before_scheduling_process_termination(): void
    {
        File::ensureDirectoryExists(dirname($this->statePath));
        File::put($this->statePath, json_encode(['pid' => 2468, 'token' => 'owned']));
        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('isRunning')->with(2468)->andReturnTrue();
        $processes->expects('terminate')->never();
        $backup = Mockery::mock(BackupService::class);
        $backup->expects('createAutomaticIfNeeded')->once()->andReturn([
            'path' => 'backup.json',
            'filename' => 'backup.json',
        ]);

        $service = new ApplicationLifecycleService($processes, Mockery::mock(AvailabilityChecker::class), $backup);

        $result = $service->prepareShutdown(false, false);

        $this->assertSame(2468, $result['pid']);
        $this->assertSame('backup.json', $result['backup']['filename']);
        $this->assertTrue(json_decode(File::get($this->statePath), true)['shutdown_requested']);
    }

    public function test_shutdown_failure_does_not_schedule_termination(): void
    {
        File::ensureDirectoryExists(dirname($this->statePath));
        File::put($this->statePath, json_encode(['pid' => 1357, 'token' => 'owned']));
        $processes = Mockery::mock(ProcessExecutor::class);
        $processes->expects('isRunning')->with(1357)->andReturnTrue();
        $processes->expects('terminate')->never();
        $backup = Mockery::mock(BackupService::class);
        $backup->expects('createAutomaticIfNeeded')->andThrow(new InvalidBackupException('backup failed'));

        $service = new ApplicationLifecycleService($processes, Mockery::mock(AvailabilityChecker::class), $backup);

        $this->expectExceptionMessage('backup failed');
        $service->prepareShutdown(false, false);
    }
}
