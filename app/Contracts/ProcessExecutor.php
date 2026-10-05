<?php

namespace App\Contracts;

interface ProcessExecutor
{
    public function start(string $workingDirectory, string $host, int $port): int;

    /** @param array<int, string> $command */
    public function startDetached(array $command, string $workingDirectory): void;

    public function isRunning(int $pid): bool;

    public function terminate(int $pid): void;
}
