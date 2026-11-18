<?php

namespace Tests\Feature;

use App\Commerce\QuoteCalculator;
use App\Commerce\QuoteDesigns;
use App\Commerce\QuotePdf;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Tests\TestCase;

class PremiumQuoteDesignsTest extends TestCase
{
    public function test_twelve_premium_documents_are_letter_and_preserve_every_snapshot(): void
    {
        $designs = app(QuoteDesigns::class);
        $directory = storage_path('app/private/qa/designs');
        File::ensureDirectoryExists($directory, 0700);
        $manifest = [];
        foreach (QuoteDesigns::LAB_CONCEPTS as $concept => $name) {
            foreach ([1, 3, 10, 35] as $count) {
                $snapshot = $designs->fixture($count);
                $original = $snapshot;
                $this->assertSame($snapshot['totals'], app(QuoteCalculator::class)->calculate($snapshot['items'], 1600, 25000));
                $html = $designs->html($concept, $snapshot);
                $this->assertStringContainsString('width:215.9mm', $html);
                $this->assertStringContainsString('DATOS FICTICIOS', $html);
                $this->assertStringContainsString('border-radius:', $html);
                $this->assertStringNotContainsString('src="http', $html);
                if ($count > 1) {
                    $this->assertStringContainsString('Fotografía no disponible', $html);
                }
                $bytes = $designs->render($concept, $snapshot);
                $this->assertStringStartsWith('%PDF-', $bytes);
                $path = $directory.'/'.$concept.'-'.$count;
                File::put($path.'.pdf', $bytes);
                File::put($path.'.html', $html);
                $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
                $pages = $reader->setSourceFile($path.'.pdf');
                for ($page = 1; $page <= $pages; $page++) {
                    $size = $reader->getTemplateSize($reader->importPage($page));
                    $this->assertEqualsWithDelta(215.9, $size['width'], .02);
                    $this->assertEqualsWithDelta(279.4, $size['height'], .02);
                }
                $this->assertGreaterThan(0, $pages);
                $this->assertLessThanOrEqual($count === 1 ? 1 : $count + 6, $pages, $concept.'-'.$count);
                $this->assertSame($original, $snapshot);
                $manifest[] = ['file' => $concept.'-'.$count.'.pdf', 'name' => $name, 'pages' => $pages, 'items' => $count, 'total_minor' => $snapshot['totals']['total'], 'sha256' => hash('sha256', $bytes)];
            }
        }
        File::put($directory.'/premium-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame('usbstock-2-letter', QuotePdf::VERSION);
        $this->assertEmpty(glob(storage_path('app/private/pdf-temp/*'), GLOB_ONLYDIR));
    }

    public function test_all_premium_views_escape_customer_and_product_content(): void
    {
        $designs = app(QuoteDesigns::class);
        $snapshot = $designs->fixture(1);
        $snapshot['customer']['name'] = '<script>customer</script>';
        $snapshot['items'][0]['product']['description'] = '<img src="https://example.test/private">';
        foreach (array_keys(QuoteDesigns::LAB_CONCEPTS) as $concept) {
            $html = $designs->html($concept, $snapshot);
            $this->assertStringContainsString('&lt;script&gt;customer&lt;/script&gt;', $html);
            $this->assertStringNotContainsString('<script>customer', $html);
            $this->assertStringNotContainsString('<img src="https://', $html);
        }
    }
}
