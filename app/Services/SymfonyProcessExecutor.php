<?php

namespace App\Services;

use App\Contracts\ProcessExecutor;
use Symfony\Component\Process\Process;

class SymfonyProcessExecutor implements ProcessExecutor
{
    public function start(string $workingDirectory, string $host, int $port): int
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'serve',
            '--host='.$host,
            '--port='.$port,
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->startWindowsProcess($command, $workingDirectory);
        }

        $process = new Process($command, $workingDirectory);

        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();
        $process->start();

        $pid = $process->getPid();
        if ($pid === null) {
            throw new \RuntimeException('O processo do servidor não informou um PID.');
        }

        return $pid;
    }

    /** @param array<int, string> $command */
    public function startDetached(array $command, string $workingDirectory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->startWindowsProcess($command, $workingDirectory);

            return;
        }

        $process = new Process($command, $workingDirectory);
        $process->setOptions(['create_new_console' => true]);
        $process->disableOutput();
        $process->start();
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

    /** @param array<int, string> $command */
    private function startWindowsProcess(array $command, string $workingDirectory): int
    {
        $launcher = new Process([
            'powershell.exe',
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-WindowStyle',
            'Hidden',
            '-Command',
            $this->buildWindowsLaunchScript($command, $workingDirectory),
        ], $workingDirectory);
        $launcher->run();

        if ($launcher->getExitCode() !== 0) {
            throw new \RuntimeException('Não foi possível iniciar o processo oculto: '.$launcher->getErrorOutput());
        }

        $pid = (int) trim($launcher->getOutput());
        if ($pid <= 0) {
            throw new \RuntimeException('O processo oculto não informou um PID válido.');
        }

        return $pid;
    }

    /** @param array<int, string> $command */
    private function buildWindowsLaunchScript(array $command, string $workingDirectory): string
    {
        $executable = array_shift($command);
        $arguments = implode(', ', array_map($this->quotePowerShell(...), $command));

        return '$process = Start-Process -FilePath '.$this->quotePowerShell((string) $executable)
            .' -ArgumentList @('.$arguments.') -WorkingDirectory '
            .$this->quotePowerShell($workingDirectory)
            .' -WindowStyle Hidden -PassThru; Write-Output $process.Id;';
    }

    private function quotePowerShell(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
