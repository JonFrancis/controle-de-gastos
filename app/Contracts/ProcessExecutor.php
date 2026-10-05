<?php

namespace App\Contracts;

interface ProcessExecutor
{
    public function start(string $workingDirectory, string $host, int $port): int;

    public function isRunning(int $pid): bool;

    public function terminate(int $pid): void;
}
