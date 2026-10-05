<?php

namespace App\Services;

use App\Contracts\ProcessExecutor;
use Symfony\Component\Process\Process;

class SymfonyProcessExecutor implements ProcessExecutor
{
    public function start(string $workingDirectory, string $host, int $port): int
    {
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'serve',
            '--host='.$host,
            '--port='.$port,
        ], $workingDirectory);

        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();
        $process->start();

        $pid = $process->getPid();
        if ($pid === null) {
            throw new \RuntimeException('O processo do servidor não informou um PID.');
        }

        return $pid;
    }

    public function isRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL');

            return is_string($output) && str_contains($output, (string) $pid);
        }

        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    public function terminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /PID '.$pid.' /T /F 2>NUL');

            return;
        }

        if (function_exists('posix_kill')) {
            posix_kill($pid, 15);
        }
    }
}
