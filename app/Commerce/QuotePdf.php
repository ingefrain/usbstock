<?php

namespace App\Commerce;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Container\SimpleContainer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class QuotePdf
{
    public const VERSION = 'usbstock-2-letter';

    public function render(array $snapshot): string
    {
        $directory = storage_path('app/private/pdf-temp/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            $pdf = new Mpdf(['tempDir' => $directory, 'format' => 'Letter', 'orientation' => 'P', 'margin_top' => 18, 'margin_bottom' => 18, 'margin_left' => 14, 'margin_right' => 14, 'default_font' => 'dejavusans', 'default_font_size' => 9], new SimpleContainer(['assetFetcher' => new BlockedPdfAssets]));
            $pdf->imageVars['usbStockLogo'] = File::get(resource_path('pdf/logo.png'));
            $pdf->SetTitle('Cotización '.$snapshot['folio']);
            $pdf->SetAuthor('USB Stock');
            $pdf->SetHTMLFooter('<div style="font-size:8pt;color:#666;border-top:1px solid #ddd;padding-top:6px">USB Stock · '.e($snapshot['folio']).' · v'.(int) $snapshot['version'].' <span style="float:right">Página {PAGENO} de {nbpg}</span></div>');
            $pdf->WriteHTML(view('pdf.quote', ['s' => $snapshot])->render());

            return $pdf->Output('', Destination::STRING_RETURN);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
