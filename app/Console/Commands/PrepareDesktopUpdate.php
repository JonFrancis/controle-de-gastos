<?php

namespace App\Console\Commands;

use App\Exceptions\DesktopDataMigrationException;
use App\Services\DesktopDataMigrationService;
use Illuminate\Console\Command;

class PrepareDesktopUpdate extends Command
{
    protected $signature = 'desktop:prepare-update';

    protected $description = 'Cria um backup do banco antes de uma atualização manual do aplicativo desktop';

    public function handle(DesktopDataMigrationService $migration): int
    {
        try {
            $this->info('Backup criado: '.$migration->createUpdateBackup());
        } catch (DesktopDataMigrationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
