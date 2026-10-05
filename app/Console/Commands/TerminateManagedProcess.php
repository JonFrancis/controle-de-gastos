<?php

namespace App\Console\Commands;

use App\Contracts\ProcessExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TerminateManagedProcess extends Command
{
    protected $signature = 'app:terminate {pid : PID do servidor gerenciado} {--delay=750 : Atraso antes de finalizar o processo, em milissegundos}';

    protected $description = 'Finaliza o processo local associado ao Controle de Gastos';

    public function handle(ProcessExecutor $processes): int
    {
        $pid = (int) $this->argument('pid');
        usleep(max(0, (int) $this->option('delay')) * 1000);

        if ($processes->isRunning($pid)) {
            $processes->terminate($pid);
        }

        $statePath = (string) config('lifecycle.state_path');
        if (File::isFile($statePath)) {
            $state = json_decode(File::get($statePath), true);
            if (is_array($state) && (int) ($state['pid'] ?? 0) === $pid) {
                File::delete($statePath);
            }
        }

        return self::SUCCESS;
    }
}
