<?php

namespace App\Console\Commands;

use App\Contracts\BrowserLauncher;
use App\Exceptions\ApplicationLifecycleException;
use App\Services\ApplicationLifecycleService;
use Illuminate\Console\Command;

class LaunchApplication extends Command
{
    protected $signature = 'app:launch';

    protected $description = 'Inicia o servidor local e abre o Controle de Gastos no navegador';

    public function handle(ApplicationLifecycleService $lifecycle, BrowserLauncher $browser): int
    {
        try {
            $url = $lifecycle->ensureStarted();
            $browser->open($url);
        } catch (ApplicationLifecycleException $exception) {
            $this->error($exception->getMessage());
            $this->line('Consulte o log da aplicação para mais detalhes.');

            return self::FAILURE;
        }

        $this->info('Controle de Gastos disponível em '.$url);

        return self::SUCCESS;
    }
}
