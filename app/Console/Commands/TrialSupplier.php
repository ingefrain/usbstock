<?php

namespace App\Console\Commands;

use App\Commerce\SupplierTrial;
use App\Models\User;
use Illuminate\Console\Command;

class TrialSupplier extends Command
{
    protected $signature = 'supplier:trial {--actor=} {--baseline=} {--download : Solo después de autorización expresa; realiza consultas de metadatos y una descarga}';

    protected $description = 'Preflight sin red por defecto; ensayo autorizado sin aplicar ni notificar';

    public function handle(SupplierTrial $trial): int
    {
        try {
            $actor = User::find($this->option('actor'));
            $baseline = (string) $this->option('baseline');
            $check = $trial->preflight($actor, $baseline);
            if (! $check['ready'] || ! $this->option('download')) {
                $this->line(json_encode($check, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $check['ready'] ? self::SUCCESS : self::FAILURE;
            }
            $report = $trial->download($actor, $baseline);
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['catalog_unchanged'] && ! $report['applied'] && $report['status'] === 'ready'
                && ($report['comparison']['comparable'] ?? false) && ! ($report['comparison']['changes']['suspicious'] ?? true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Ensayo no completado. Revisa configuración privada, preflight y corridas; no se muestran errores sensibles.');

            return self::FAILURE;
        }
    }
}
