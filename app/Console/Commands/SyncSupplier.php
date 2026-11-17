<?php

namespace App\Console\Commands;

use App\Commerce\CommerceAccess;
use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierSync;
use App\Models\User;
use App\Support\AdminAuditLogger;
use Illuminate\Console\Command;

class SyncSupplier extends Command
{
    protected $signature = 'supplier:sync {file?} {--fetch} {--apply} {--actor=}';

    protected $description = 'Valida un CSV local o descarga autorizada; por defecto solo genera vista previa';

    public function handle(SupplierSync $sync, SupplierDownloader $download): int
    {
        try {
            $actor = $this->option('actor') ? User::find($this->option('actor')) : null;
            if ($this->option('apply') && ! CommerceAccess::allows($actor, 'supplier_sync.apply')) {
                $this->error('Aplicar requiere --actor con permiso supplier_sync.apply.');

                return self::FAILURE;
            }
            if ($this->option('fetch')) {
                $bytes = $download->download();
                $filename = 'provider.csv';
            } else {
                $filename = (string) $this->argument('file');
                if (! is_file($filename) || filesize($filename) > config('commerce.sync.max_bytes')) {
                    $this->error('Selecciona un CSV local válido de hasta 8 MiB.');

                    return self::FAILURE;
                }
                $bytes = file_get_contents($filename);
            }
            $run = $sync->preview($bytes, basename($filename), $actor?->id, $this->option('fetch') ? 'ftp' : 'local');
            if ($this->option('apply') && $run->status === 'ready') {
                $run = $sync->apply($run, $actor->id);
            }
            $this->info($run->uuid.' · '.$run->status.' · '.$run->row_count.' registros');

            return in_array($run->status, ['ready', 'completed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            app(AdminAuditLogger::class)->record('supplier_sync.failed', 'supplier_sync', null, after: ['source' => $this->option('fetch') ? 'ftp' : 'local'], actorId: $actor?->id ?? null);
            $this->error('La operación no se completó. Revisa el archivo y la configuración; no se muestran detalles sensibles.');

            return self::FAILURE;
        }
    }
}
