<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidBackupException;
use App\Http\Requests\RestoreBackupRequest;
use App\Http\Requests\UpdateBackupSettingsRequest;
use App\Models\AppSetting;
use App\Services\AuditService;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function index(): Response
    {
        $setting = AppSetting::query()->firstOrFail();
        $directory = app(BackupService::class)->defaultDirectory();
        $backups = File::isDirectory($directory) ? collect(File::files($directory)) : collect();

        return Inertia::render('Settings/Backups', [
            'settings' => [
                'automaticBackupEnabled' => $setting->automatic_backup_enabled,
                'backupPath' => $setting->backup_path,
            ],
            'backups' => $backups
                ->sortByDesc(fn ($file) => $file->getMTime())
                ->take(20)
                ->values()
                ->map(fn ($file): array => [
                    'filename' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'createdAt' => now()->setTimestamp($file->getMTime())->toIso8601String(),
                ]),
        ]);
    }

    public function update(UpdateBackupSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        AppSetting::query()->firstOrFail()->update([
            'automatic_backup_enabled' => (bool) ($data['automatic_backup_enabled'] ?? false),
            'backup_path' => $data['backup_path'] ?? null,
        ]);

        return to_route('settings.backups')->with('success', 'Configurações de backup salvas.');
    }

    public function store(BackupService $service): RedirectResponse
    {
        try {
            $backup = $service->create();
        } catch (InvalidBackupException $exception) {
            return to_route('settings.backups')->withErrors(['backup' => $exception->getMessage()]);
        }

        return to_route('settings.backups')->with('success', "Backup criado: {$backup['filename']}.");
    }

    public function restore(RestoreBackupRequest $request, BackupService $service, AuditService $audit): RedirectResponse
    {
        try {
            $previous = $service->restore($request->file('backup'));
        } catch (InvalidBackupException $exception) {
            return to_route('settings.backups')->withErrors(['backup' => $exception->getMessage()]);
        }

        $audit->record('restore', metadata: ['previous_backup_path' => $previous['path']]);

        return to_route('settings.backups')->with('success', "Banco restaurado. Cópia anterior preservada em {$previous['filename']}.");
    }
}
