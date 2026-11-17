<?php

namespace Tests\Feature;

use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommerceCatalogTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function preview(string $rows)
    {
        return app(SupplierSync::class)->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n".$rows, 'fixture.csv', null);
    }

    public function test_preview_apply_and_reimport_preserve_exact_descriptions_and_customizations(): void
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $run = $this->preview("USB1-A,100,\"  Descripción, original  \",20.50\nUSB1-B,80,Otra variante,21");
        $this->assertSame('ready', $run->status);
        $this->assertDatabaseCount('products', 0);
        $sync->apply($run, null);
        $product = Product::sole();
        $this->assertFalse($product->is_published);
        $this->assertSame('  Descripción, original  ', $product->description);
        $product->update(['custom_name' => 'Título propio', 'custom_description' => 'Texto propio', 'is_published' => true]);
        $product->variants()->first()->update(['color_name' => 'Azul propio', 'is_enabled' => false]);
        $next = $this->preview("USB1-A,90,Nueva descripción,22.00\nUSB1-B,80,Otra variante,21");
        $sync->apply($next, null);
        $this->assertSame('Texto propio', $product->fresh()->description);
        $this->assertSame('Nueva descripción', $product->fresh()->supplier_description);
        $this->assertTrue($product->fresh()->is_published);
        $this->assertFalse($product->variants()->first()->is_enabled);
        $this->assertSame(2200, $product->variants()->first()->supplier_price_minor);
        $this->expectException(HttpException::class);
        $sync->apply($next, null);
    }

    public function test_invalid_partial_duplicate_corrupt_and_empty_files_cannot_change_catalog(): void
    {
        Storage::fake('local');
        foreach (["USB1-A,20,Correcto,2\nUSB1-A,21,Duplicado,2", 'USB1-A,-1,Incorrecto,2', 'USB1-A,2,Incorrecto,2.001', 'USB1-A,2,"Sin cierre,2', "USB1-A,2,\xff,2", ''] as $rows) {
            $run = $this->preview($rows);
            $this->assertSame('invalid', $run->status);
            try {
                app(SupplierSync::class)->apply($run, null);
                $this->fail('Aplicación inválida');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_large_changes_stale_previews_and_tampering_require_review(): void
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $sync->apply($this->preview("USB1-A,20,Uno,2\nUSB2-A,20,Dos,2"), null);
        $run = $this->preview('USB1-A,20,Uno,5');
        $this->assertSame('review', $run->status);
        try {
            $sync->apply($run, null);
            $this->fail('Falta aprobación');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $stale = $this->preview('USB1-A,20,Uno,5');
        $sync->apply($run, null, true, true);
        $this->assertFalse(ProductVariant::where('sku', 'USB2-A')->sole()->provider_active);
        try {
            $sync->apply($stale, null, false, true);
            $this->fail('Vista obsoleta');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $tampered = $this->preview('USB1-A,20,Uno,5');
        Storage::disk('local')->put($tampered->path, 'changed');
        $this->expectException(HttpException::class);
        $sync->apply($tampered, null);
    }

    public function test_admin_routes_permissions_and_safe_defaults(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        app(SupplierSync::class)->apply($this->preview('USB1-A,100,Uno,2'), null);
        $product = Product::sole();
        $this->get(route('admin.products.index'))->assertOk();
        $this->get(route('admin.products.edit', $product))->assertOk();
        $this->get(route('admin.product-categories.index'))->assertOk();
        $this->get(route('admin.supplier-sync.index'))->assertOk();
        $this->get(route('admin.supplier-sync.show', $this->preview('USB1-A,100,Uno,2')))->assertOk();
        $this->actingAsAdmin($this->userWithRole('operator'))->get(route('admin.products.index'))->assertForbidden();
        $this->put(route('admin.products.update', $product), ['is_published' => 1])->assertForbidden();
        $this->assertFalse(config('commerce.sync.enabled'));
        $this->expectException(\RuntimeException::class);
        app(SupplierDownloader::class)->download();
    }

    public function test_inventory_collapse_requires_additional_review_without_losing_stock(): void
    {
        Storage::fake('local');
        app(SupplierSync::class)->apply($this->preview('USB1-A,1000,Prueba,20'), null);
        $run = $this->preview('USB1-A,0,Prueba,20');
        $this->assertSame('review', $run->status);
        $this->assertTrue($run->summary['stock_drop']);
        $this->assertSame(1000, ProductVariant::sole()->stock);
    }
}
