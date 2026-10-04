<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function __construct(private readonly BackupService $backupService) {}

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues @param array<string, mixed> $metadata */
    public function record(string $action, ?Model $subject = null, ?array $oldValues = null, ?array $newValues = null, array $metadata = []): AuditLog
    {
        $log = AuditLog::create([
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);

        $this->backupService->createAutomaticIfEnabled();

        return $log;
    }

    /** @param array<string, mixed> $metadata */
    public function recordImport(string $source, int $records, array $metadata = []): AuditLog
    {
        return $this->record(AuditLog::ACTION_IMPORT, metadata: ['source' => $source, 'records' => $records, ...$metadata]);
    }
}
