<?php

namespace App\Providers;

use App\Services\ApplicationLifecycleService;
use App\Services\DesktopDataMigrationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        try {
            $migration = app(DesktopDataMigrationService::class)->migrate();
            Log::info('Migração do banco desktop concluída.', $migration);
        } catch (\Throwable $exception) {
            Log::error('Falha na migração do banco desktop.', ['message' => $exception->getMessage()]);
        }

        Window::open('main');

        Event::listen(WindowClosed::class, function (): void {
            try {
                app(ApplicationLifecycleService::class)->prepareShutdown(false, true);
            } catch (\Throwable $exception) {
                Log::error('Falha no encerramento da janela desktop.', ['message' => $exception->getMessage()]);
            }
        });
    }

    /** @return array<string, string> */
    public function phpIni(): array
    {
        return [
            'display_errors' => '1',
            'max_execution_time' => '0',
        ];
    }
}
