<?php

namespace App\Console\Commands;

use App\Commerce\SupplierNotifications;
use App\Models\SupplierSyncRun;
use Illuminate\Console\Command;

class RetrySupplierNotifications extends Command
{
    protected $signature = 'supplier:notify {run : UUID de una ejecución terminada}';

    protected $description = 'Reconcilia el aviso operativo con Outbox; no repite mensajes enviados o inciertos';

    public function handle(SupplierNotifications $notifications): int
    {
        $run = SupplierSyncRun::where('uuid', $this->argument('run'))->whereNotNull('finished_at')->first();
        if (! $run || ! config('commerce.telegram_enabled')) {
            $this->error('Ejecución inexistente/en curso o notificaciones deshabilitadas.');

            return self::FAILURE;
        }
        $notifications->record($run);
        $this->info('Aviso reconciliado; consulta su estado en Outbox.');

        return self::SUCCESS;
    }
}
