<?php

namespace Tests\Feature;

use App\Commerce\BlockedPdfAssets;
use App\Commerce\QuoteCalculator;
use App\Commerce\QuotePdf;
use Mpdf\Exception\AssetFetchingException;
use Mpdf\Mpdf;
use Tests\TestCase;

class CommercePdfTest extends TestCase
{
    public function test_multi_page_pdf_uses_escaped_text_and_local_logo_without_external_assets(): void
    {
        $items = [];
        foreach (range(1, 35) as $i) {
            $items[] = ['product' => ['sku' => 'PRUEBA-'.$i, 'name' => 'Memoria de prueba '.$i, 'color' => 'Azul', 'capacity_gb' => 16, 'description' => 'Descripción ficticia con acentos: México, impresión, información. '.str_repeat('Contenido de prueba para verificar saltos de página. ', 4)], 'quantity' => 50, 'unit_minor' => 2000, 'printing_minor' => 200, 'discount_minor' => 100, 'printing' => 'Impresión de prueba'];
        }
        $s = ['folio' => 'PRUEBA-NO-COMERCIAL', 'version' => 1, 'issued_at' => '2026-11-17T12:00:00-06:00', 'valid_until' => '2026-12-17', 'issuer' => ['issuer_name' => 'EMISOR FICTICIO · DOCUMENTO DE PRUEBA', 'issuer_contact' => 'prueba@example.test'], 'customer' => ['name' => 'Cliente ficticio', 'email' => 'cliente@example.test'], 'seller' => 'Vendedor ficticio', 'terms' => 'Documento ficticio para validar diseño. No representa una oferta comercial.', 'tax_basis_points' => 1600, 'items' => $items, 'totals' => (new QuoteCalculator)->calculate($items, 1600, 5000)];
        $html = view('pdf.quote', ['s' => $s])->render();
        $this->assertStringContainsString('var:usbStockLogo', $html);
        $bytes = app(QuotePdf::class)->render($s);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
        $directory = storage_path('app/private/qa');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/quotation-multipage.pdf';
        file_put_contents($path, $bytes);
        $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
        $this->assertGreaterThan(2, $reader->setSourceFile($path));
        $s['customer']['name'] = '<img src="http://127.0.0.1/private">';
        $this->assertStringContainsString('&lt;img', view('pdf.quote', ['s' => $s])->render());
        $this->assertEmpty(glob(storage_path('app/private/pdf-temp/*'), GLOB_ONLYDIR));
        $this->expectException(AssetFetchingException::class);
        (new BlockedPdfAssets)->fetchDataFromPath('file:///etc/passwd');
    }
}
