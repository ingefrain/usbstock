<?php

namespace Tests\Feature;

use App\Commerce\QuoteCalculator;
use App\Commerce\QuoteDesigns;
use App\Commerce\QuotePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class QuoteDesignsTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    public function test_twelve_real_pdf_scenarios_preserve_calculations_and_local_assets(): void
    {
        $designs = app(QuoteDesigns::class);
        $directory = storage_path('app/private/qa/designs');
        File::ensureDirectoryExists($directory, 0700);
        $manifest = [];
        foreach (array_keys(QuoteDesigns::CONCEPTS) as $concept) {
            foreach ([1, 3, 10, 35] as $count) {
                $snapshot = $designs->fixture($count);
                $this->assertSame($snapshot['totals'], app(QuoteCalculator::class)->calculate($snapshot['items'], 1600, 25000));
                $html = $designs->html($concept, $snapshot);
                $this->assertStringContainsString('width:210mm', $html);
                $this->assertStringContainsString('DATOS FICTICIOS', $html);
                $this->assertStringContainsString('border-radius:', $html);
                $this->assertStringNotContainsString('src="http', $html);
                if ($count > 1) {
                    $this->assertStringContainsString('no disponible', $html);
                }
                $bytes = $designs->render($concept, $snapshot);
                $this->assertStringStartsWith('%PDF-', $bytes);
                $path = $directory.'/'.$concept.'-'.$count;
                file_put_contents($path.'.pdf', $bytes);
                file_put_contents($path.'.html', $html);
                $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
                $pages = $reader->setSourceFile($path.'.pdf');
                $this->assertGreaterThan(0, $pages);
                $this->assertLessThanOrEqual($count === 1 ? 1 : $count + 6, $pages, 'Unexpected pagination explosion: '.$concept.'-'.$count);
                if ($count === 35) {
                    $this->assertGreaterThan(3, $pages);
                }
                $manifest[] = ['file' => $concept.'-'.$count.'.pdf', 'pages' => $pages, 'items' => $count, 'total_minor' => $snapshot['totals']['total']];
            }
        }
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->assertEmpty(glob(storage_path('app/private/pdf-temp/*'), GLOB_ONLYDIR));
    }

    public function test_design_previews_require_permission_and_are_hidden_in_production(): void
    {
        $this->get(route('admin.pdf-designs.index'))->assertRedirect();
        $this->actingAsAdmin()->get(route('admin.pdf-designs.index'))->assertOk()->assertSee('Premium corporativo');
        $this->get(route('admin.pdf-designs.preview', 'a'))->assertOk()->assertSee('DATOS FICTICIOS')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get(route('admin.pdf-designs.preview', ['concept' => 'a', 'items' => 999]))->assertSessionHasErrors('items');
        $this->get(route('admin.pdf-designs.preview', 'unknown'))->assertNotFound();
        $this->actingAsAdmin($this->userWithRole('operator'))->get(route('admin.pdf-designs.index'))->assertForbidden();
        $this->actingAsAdmin();
        $this->app->instance('env', 'production');
        $this->get(route('admin.pdf-designs.index'))->assertNotFound();
        $this->get(route('admin.pdf-designs.preview', 'a'))->assertNotFound();
    }

    public function test_proposal_escapes_untrusted_content_without_changing_emission_template(): void
    {
        $snapshot = app(QuoteDesigns::class)->fixture(1);
        $snapshot['customer']['name'] = '<script>secret</script>';
        $html = app(QuoteDesigns::class)->html('a', $snapshot);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertSame('usbstock-1', QuotePdf::VERSION);
    }
}
