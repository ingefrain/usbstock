<?php

namespace App\Console\Commands;

use App\Commerce\CommerceAccess;
use App\Commerce\SupplierNotifications;
use App\Commerce\SupplierPipeline;
use App\Commerce\SupplierReadOnly;
use App\Commerce\SupplierSync;
use App\Models\User;
use App\Support\AdminAuditLogger;
use Illuminate\Console\Command;

class SyncSupplier extends Command
{
    protected $signature = 'supplier:sync {file?} {--fetch} {--apply} {--dry-run} {--scheduled} {--actor=}';

    protected $description = 'Valida un CSV local o descarga autorizada; por defecto solo genera vista previa';

    public function handle(SupplierSync $sync, SupplierPipeline $pipeline, SupplierNotifications $notifications): int
    {
        if ($this->option('dry-run')) {
            return app(SupplierReadOnly::class)->run(fn () => $this->executeSync($sync, $pipeline, $notifications));
        }

        return $this->executeSync($sync, $pipeline, $notifications);
    }

    private function executeSync(SupplierSync $sync, SupplierPipeline $pipeline, SupplierNotifications $notifications): int
    {
        try {
            $scheduled = (bool) $this->option('scheduled');
            if ($scheduled && (! config('commerce.sync.enabled') || ! $this->option('fetch') || $this->argument('file'))) {
                $this->error('Programación deshabilitada o argumentos incompatibles.');

                return self::FAILURE;
            }
            $apply = ! $this->option('dry-run') && ($scheduled ? config('commerce.sync.auto_apply') : $this->option('apply'));
            $actorId = $scheduled ? config('commerce.sync.actor_id') : $this->option('actor');
            $actor = $actorId ? User::find($actorId) : null;
            if ($apply && ! CommerceAccess::allows($actor, 'supplier_sync.apply')) {
                $this->error('Aplicar requiere --actor con permiso supplier_sync.apply.');

                return self::FAILURE;
            }
            if ($this->option('fetch')) {
                if (! CommerceAccess::allows($actor, 'supplier_sync.create') || $this->argument('file')) {
                    $this->error('Descargar requiere --actor con permiso supplier_sync.create y ningún archivo local.');

                    return self::FAILURE;
                }
                $run = $pipeline->fetch($actor->id, $apply, $scheduled);
            } else {
                $filename = (string) $this->argument('file');
                if (! is_file($filename) || filesize($filename) > config('commerce.sync.max_bytes')) {
                    $this->error('Selecciona un CSV local válido de hasta 8 MiB.');

                    return self::FAILURE;
                }
                $bytes = file_get_contents($filename);
                $run = $sync->preview($bytes, basename($filename), $actor?->id);
                if ($apply && $run->status === 'ready') {
                    $run = $sync->apply($run, $actor->id);
                }
                $notifications->record($run);
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
