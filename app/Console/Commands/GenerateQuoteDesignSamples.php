<?php

namespace App\Console\Commands;

use App\Commerce\QuoteDesigns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

class GenerateQuoteDesignSamples extends Command
{
    protected $signature = 'quotes:design-samples';

    protected $description = 'Genera las doce propuestas ficticias privadas de revisión (solo desarrollo)';

    public function handle(QuoteDesigns $designs): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Las muestras solo se generan en desarrollo.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/qa/designs');
        File::ensureDirectoryExists($directory, 0700);
        $manifest = [];
        foreach (array_keys(QuoteDesigns::CONCEPTS) as $concept) {
            foreach ([1, 3, 10, 35] as $count) {
                $snapshot = $designs->fixture($count);
                $stem = $concept.'-'.$count;
                File::put($directory.'/'.$stem.'.html', $designs->html($concept, $snapshot));
                File::put($directory.'/'.$stem.'.pdf', $designs->render($concept, $snapshot));
                $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
                $pages = $reader->setSourceFile($directory.'/'.$stem.'.pdf');
                $manifest[] = ['file' => $stem.'.pdf', 'pages' => $pages, 'items' => $count, 'total_minor' => $snapshot['totals']['total']];
                $this->line($stem.' · '.$pages.' páginas');
            }
        }
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->info('Muestras ficticias generadas en storage/app/private/qa/designs. No se crearon cotizaciones.');

        return self::SUCCESS;
    }
}
