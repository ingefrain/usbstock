<?php

namespace App\Console\Commands;

use App\Commerce\SupplierCsvComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompareSupplierFiles extends Command
{
    protected $signature = 'supplier:compare {baseline} {current}';

    protected $description = 'Compara dos CSV locales, sin red ni cambios de base de datos';

    public function handle(SupplierCsvComparison $comparison): int
    {
        foreach (['baseline', 'current'] as $key) {
            $path = $this->argument($key);
            if (! is_file($path) || ! is_readable($path) || filesize($path) > config('commerce.sync.max_bytes')) {
                $this->error('Selecciona dos CSV locales legibles de hasta 8 MiB.');

                return self::FAILURE;
            }
        }
        $report = $comparison->compare(file_get_contents($this->argument('baseline')), file_get_contents($this->argument('current')));
        $path = 'supplier-comparisons/'.Str::uuid().'.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $this->error('No se pudo guardar el informe privado.');

            return self::FAILURE;
        }
        $safe = $report;
        unset($safe['changes']['skus']);
        $this->line(json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Informe privado: '.$path);

        return $report['comparable'] ? self::SUCCESS : self::FAILURE;
    }
}
