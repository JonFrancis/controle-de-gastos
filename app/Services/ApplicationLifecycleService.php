<?php

namespace App\Services;

use App\Contracts\AvailabilityChecker;
use App\Contracts\ProcessExecutor;
use App\Exceptions\ApplicationLifecycleException;
use App\Exceptions\OperationInProgressException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ApplicationLifecycleService
{
    public function __construct(
        private readonly ProcessExecutor $processes,
        private readonly AvailabilityChecker $availability,
        private readonly BackupService $backup,
    ) {}

    /** @return array{running: bool, pid: int|null, url: string, owned: bool, shutdownRequested: bool} */
    public function status(): array
    {
        $state = $this->readState();
        $pid = isset($state['pid']) ? (int) $state['pid'] : null;
        $running = $pid !== null && $this->processes->isRunning($pid);

        return [
            'running' => $running,
            'pid' => $running ? $pid : null,
            'url' => $this->url(),
            'owned' => $running && ($state['token'] ?? null) !== null,
            'shutdownRequested' => (bool) ($state['shutdown_requested'] ?? false),
        ];
    }

    public function url(): string
    {
        return 'http://'.config('lifecycle.host').':'.config('lifecycle.port');
    }

    public function ensureStarted(): string
    {
        $status = $this->status();
        if ($this->availability->responds($this->url())) {
            return $this->url();
        }

        if ($status['running'] && $status['owned'] && $status['pid'] !== null) {
            $this->processes->terminate($status['pid']);
            $this->clearState();
        }

        $pid = null;
        try {
            $pid = $this->processes->start(base_path(), config('lifecycle.host'), config('lifecycle.port'));
            $this->writeState([
                'pid' => $pid,
                'token' => bin2hex(random_bytes(16)),
                'host' => config('lifecycle.host'),
                'port' => config('lifecycle.port'),
                'started_at' => now()->toIso8601String(),
                'shutdown_requested' => false,
            ]);

            $deadline = microtime(true) + config('lifecycle.startup_timeout_seconds');
            while (microtime(true) < $deadline) {
                if ($this->availability->responds($this->url())) {
                    return $this->url();
                }

                usleep(100_000);
            }

            throw new ApplicationLifecycleException('A aplicação não respondeu dentro do tempo esperado.');
        } catch (\Throwable $exception) {
            if ($pid !== null && $this->processes->isRunning($pid)) {
                $this->processes->terminate($pid);
            }

            Log::channel(config('lifecycle.log_channel'))->error('Falha ao iniciar o Controle de Gastos.', [
                'url' => $this->url(),
                'pid' => $pid,
                'message' => $exception->getMessage(),
            ]);
            $this->clearState();

            throw $exception instanceof ApplicationLifecycleException
                ? $exception
                : new ApplicationLifecycleException('Não foi possível iniciar a aplicação.', previous: $exception);
        }
    }

    /** @return array{pid: int|null, backup: array{path: string, filename: string}|null} */
    public function prepareShutdown(bool $hasPendingOperation, bool $confirmed): array
    {
        if ($hasPendingOperation && ! $confirmed) {
            throw new OperationInProgressException('Existe uma operação em andamento. Confirme o encerramento para continuar.');
        }

        $status = $this->status();
        if ($status['shutdownRequested']) {
            return ['pid' => $status['pid'], 'backup' => null];
        }

        try {
            $backup = $this->backup->createAutomaticIfNeeded();
        } catch (\Throwable $exception) {
            Log::channel(config('lifecycle.log_channel'))->error('Falha no backup antes do encerramento.', [
                'message' => $exception->getMessage(),
                'pid' => $status['pid'],
            ]);

            throw $exception;
        }

        if (! $status['running'] || ! $status['owned']) {
            return ['pid' => null, 'backup' => $backup];
        }

        $this->writeState([
            ...$this->readState(),
            'shutdown_requested' => true,
        ]);

        app()->terminating(function () use ($status): void {
            if ($status['pid'] === null || ! $this->processes->isRunning($status['pid'])) {
                return;
            }

            $this->processes->startDetached([
                PHP_BINARY,
                base_path('artisan'),
                'app:terminate',
                (string) $status['pid'],
            ], base_path());
        });

        return ['pid' => $status['pid'], 'backup' => $backup];
    }

    private function statePath(): string
    {
        return (string) config('lifecycle.state_path');
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $path = $this->statePath();
        if (! File::isFile($path)) {
            return [];
        }

        $state = json_decode(File::get($path), true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function clearState(): void
    {
        File::delete($this->statePath());
    }
}
