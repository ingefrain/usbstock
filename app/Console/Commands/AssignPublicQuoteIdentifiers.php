<?php

namespace App\Console\Commands;

use App\Commerce\Api\PublicQuoteIdentifiers;
use App\Models\Quote;
use Illuminate\Console\Command;

class AssignPublicQuoteIdentifiers extends Command
{
    protected $signature = 'commerce:assign-public-quote-ids {--apply : Asignar los identificadores; sin esta opción sólo cuenta pendientes}';

    protected $description = 'Asigna IDs públicos a cotizaciones nativas ya emitidas en una base experimental aislada; no importa históricos';

    public function handle(PublicQuoteIdentifiers $identifiers): int
    {
        // Inspect configuration before opening a database connection.
        if (! $this->isolatedDestination()) {
            $this->error('Destino bloqueado: este comando sólo admite la base experimental local o la base aislada de pruebas.');

            return self::FAILURE;
        }

        $pending = Quote::query()->whereHas('versions')->whereNotIn('id', fn ($query) => $query->select('quote_id')->from('quote_public_identifiers'));
        if (! $this->option('apply')) {
            $this->info('Cotizaciones emitidas sin identificador público: '.$pending->count().'. No se realizaron cambios.');

            return self::SUCCESS;
        }

        $assigned = 0;
        try {
            $pending->chunkById(100, function ($quotes) use ($identifiers, &$assigned): void {
                foreach ($quotes as $quote) {
                    $assigned += (int) $identifiers->assign($quote)->wasRecentlyCreated;
                }
            });
        } catch (\Throwable) {
            $this->error('Asignación interrumpida. Las correspondencias ya confirmadas se conservan; se puede reanudar sin reasignarlas.');

            return self::FAILURE;
        }
        $this->info('Identificadores públicos asignados: '.$assigned.'. No se modificaron importes, versiones ni documentos.');

        return self::SUCCESS;
    }

    private function isolatedDestination(): bool
    {
        $connection = config('database.connections.'.config('database.default'), []);
        if (! empty($connection['url']) || ! empty($connection['read']) || ! empty($connection['write'])
            || ! empty($connection['unix_socket']) || ! empty($connection['prefix'])) {
            return false;
        }
        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;
        if ($driver === 'sqlite') {
            if (app()->environment('testing') && $database === ':memory:') {
                return true;
            }

            return app()->environment('local') && is_string($database)
                && str_replace('\\', '/', $database) === str_replace('\\', '/', database_path('api-grupotalento.sqlite'));
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)
            || ($connection['host'] ?? '') !== '127.0.0.1' || (string) ($connection['port'] ?? '') !== '3307') {
            return false;
        }

        return (app()->environment('local') && $database === 'usbstock_api_grupotalento')
            || (app()->environment('testing') && $database === 'usbstock_test'
                && ($connection['username'] ?? '') === 'usbstock_test_runner' && getenv('OUTBOX_MARIADB_TEST') === '1');
    }
}
