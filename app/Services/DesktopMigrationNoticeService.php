<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class DesktopMigrationNoticeService
{
    /** @return array{message: string, created_at: string}|null */
    public function current(): ?array
    {
        $path = $this->path();
        if (! File::isFile($path)) {
            return null;
        }

        $notice = json_decode(File::get($path), true);
        if (! is_array($notice) || ! is_string($notice['message'] ?? null)) {
            return null;
        }

        return [
            'message' => $notice['message'],
            'created_at' => is_string($notice['created_at'] ?? null) ? $notice['created_at'] : '',
        ];
    }

    public function record(string $message): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'message' => $message,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        File::delete($this->path());
    }

    private function path(): string
    {
        return (string) config('desktop.migration_notice_path');
    }
}
