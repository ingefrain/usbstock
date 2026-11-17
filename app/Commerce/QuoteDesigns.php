<?php

namespace App\Commerce;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Container\SimpleContainer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class QuoteDesigns
{
    public const CONCEPTS = ['a' => 'Premium corporativo', 'b' => 'Catálogo comercial', 'c' => 'Ejecutivo minimalista'];

    public const VERSION = 'review-1';

    public function fixture(int $count): array
    {
        abort_unless(in_array($count, [1, 3, 10, 35], true), 422);
        $items = [];
        foreach (range(1, $count) as $i) {
            $items[] = ['product' => ['sku' => 'PRUEBA-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'name' => $i % 2 ? 'Memoria USB Metal' : 'Memoria USB Clip', 'description' => 'Acabado metálico, formato portátil y superficie para personalización. '.($count >= 10 ? str_repeat('Descripción ficticia: impresión, México, información y garantía de muestra. ', 4) : 'Presentación comercial de prueba.'), 'color' => $i % 2 ? 'Grafito' : 'Plata', 'capacity_gb' => 8], 'quantity' => $count === 35 && $i === 35 ? 1000 : 100, 'unit_minor' => $count === 35 && $i === 35 ? 9876543 : 8500, 'printing_minor' => 650, 'discount_minor' => 15000, 'printing' => 'Grabado láser / una posición · prueba', 'photo_fixture' => $i % 3 ? ($i % 2 ? 'usb-metal.jpg' : 'usb-clip.jpg') : null];
        }
        $terms = "Documento ficticio para evaluar diseño; no constituye una oferta comercial.\n\nDisponibilidad y tiempos sujetos a confirmación. La fotografía es ilustrativa.\n\nMuestra de personalización para aprobación previa del cliente. Pago y entrega por acordar.";
        if ($count === 35) {
            foreach (range(1, 35) as $i) {
                $terms .= "\n\nCondición de prueba ".$i.'. '.str_repeat('Información ficticia para revisar continuidad, lectura, acentos y saltos de página. ', 2);
            }
        }

        return ['folio' => 'PRUEBA-2026-000001', 'version' => 1, 'issued_at' => '2026-11-17T12:00:00-06:00', 'valid_until' => '2026-12-17', 'issuer' => ['issuer_name' => 'USB Stock · Emisor ficticio', 'issuer_contact' => "Guadalajara, Jalisco · Domicilio de prueba\nventas@example.test · Teléfono de prueba"], 'customer' => ['name' => 'María Hernández · Cliente ficticio', 'company' => 'Estudio Horizonte · Empresa de prueba', 'email' => 'cliente@example.test', 'phone' => 'Contacto de prueba'], 'seller' => 'Ana García · Asesora ficticia', 'terms' => $terms, 'tax_basis_points' => 1600, 'items' => $items, 'totals' => app(QuoteCalculator::class)->calculate($items, 1600, 25000), 'calculation_version' => 'legacy_v1', 'is_fixture' => true];
    }

    public function html(string $concept, array $snapshot, bool $pdf = false): string
    {
        abort_unless(isset(self::CONCEPTS[$concept]), 404);
        $assets = $this->assets($snapshot);
        $sources = [];
        foreach ($assets as $name => $bytes) {
            $sources[$name] = $pdf ? 'var:'.$name : 'data:'.($name === 'placeholder' ? 'image/svg+xml' : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)).';base64,'.base64_encode($bytes);
        }

        $fonts = $pdf ? [] : ['regular' => base64_encode(File::get(base_path('vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf'))), 'bold' => base64_encode(File::get(base_path('vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf')))];

        return view('pdf.proposals.document', ['s' => $snapshot, 'concept' => $concept, 'name' => self::CONCEPTS[$concept], 'images' => $sources, 'pdf' => $pdf, 'fonts' => $fonts])->render();
    }

    private function assets(array $snapshot): array
    {
        $assets = ['logo' => File::get(resource_path('pdf/logo.png')), 'placeholder' => File::get(resource_path('pdf/proposals/no-photo.svg'))];
        foreach ($snapshot['items'] as $i => $line) {
            if (($snapshot['is_fixture'] ?? false) && in_array($line['photo_fixture'] ?? null, ['usb-metal.jpg', 'usb-clip.jpg'], true)) {
                $assets['photo'.$i] = File::get(resource_path('pdf/proposals/'.$line['photo_fixture']));
            } elseif (isset($line['photo_bytes'])) {
                // Only server-resolved, validated local raster bytes enter this internal view model.
                $assets['photo'.$i] = $line['photo_bytes'];
            }
        }

        return $assets;
    }

    public function render(string $concept, array $snapshot): string
    {
        abort_unless(isset(self::CONCEPTS[$concept]), 404);
        $directory = storage_path('app/private/pdf-temp/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            $pdf = new Mpdf(['tempDir' => $directory, 'format' => 'A4', 'margin_top' => 14, 'margin_bottom' => 17, 'margin_left' => 14, 'margin_right' => 14, 'default_font' => 'dejavusans', 'default_font_size' => 9, 'shrink_tables_to_fit' => 1], new SimpleContainer(['assetFetcher' => new BlockedPdfAssets]));
            $pdf->imageVars = $this->assets($snapshot);
            $pdf->SetTitle('USB Stock · Propuesta '.strtoupper($concept).' · '.$snapshot['folio']);
            $pdf->SetAuthor('USB Stock');
            $pdf->SetHTMLFooter('<table width="100%" style="font-size:7pt;color:#687078;border-top:1px solid #ddd"><tr><td>USB Stock · '.e($snapshot['folio']).' · Diseño '.strtoupper($concept).' · '.self::VERSION.'</td><td align="right">Página {PAGENO} de {nbpg}</td></tr></table>');
            $pdf->WriteHTML($this->html($concept, $snapshot, true));

            return $pdf->Output('', Destination::STRING_RETURN);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
