<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $selectedAction = $request->string('action')->toString();
        $selectedAction = in_array($selectedAction, AuditLog::actions(), true) ? $selectedAction : null;

        $logs = AuditLog::query()
            ->when($selectedAction, fn ($query) => $query->where('action', $selectedAction))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'auditableType' => $log->auditable_type,
                'auditableId' => $log->auditable_id,
                'oldValues' => $log->old_values,
                'newValues' => $log->new_values,
                'metadata' => $log->metadata,
                'createdAt' => $log->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('History/Index', [
            'logs' => $logs,
            'actions' => AuditLog::actions(),
            'selectedAction' => $selectedAction,
        ]);
    }
}
