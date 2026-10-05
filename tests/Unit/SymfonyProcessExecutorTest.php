<?php

namespace Tests\Unit;

use App\Services\SymfonyProcessExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SymfonyProcessExecutorTest extends TestCase
{
    public function test_windows_launcher_script_hides_the_detached_process(): void
    {
        $executor = new SymfonyProcessExecutor;
        $method = new ReflectionMethod($executor, 'buildWindowsLaunchScript');
        $method->setAccessible(true);

        $script = $method->invoke($executor, ['C:\\php\\php.exe', 'artisan', 'serve'], 'C:\\app');

        $this->assertStringContainsString('Start-Process', $script);
        $this->assertStringContainsString('-WindowStyle Hidden', $script);
        $this->assertStringContainsString("'artisan'", $script);
    }
}
