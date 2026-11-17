<?php

namespace Tests\Feature;

use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommercePublicTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function fixture(): Product
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nUSB1-A,500,Descripción de prueba,321.78\nUSB1-B,20,Otra variante,321.78", 'fixture.csv', null), null);

        return Product::sole();
    }

    public function test_public_visibility_filters_escaping_and_no_cost_disclosure(): void
    {
        $product = $this->fixture();
        $this->get('/')->assertOk()->assertSee('Estamos preparando el catálogo');
        $this->get(route('shop.product', $product->slug))->assertNotFound();
        $product->update(['is_published' => true, 'capacity_gb' => 16, 'custom_name' => 'Memoria <script>alert(1)</script>']);
        $this->get('/')->assertOk()->assertSee('Memoria &lt;script&gt;', false)->assertDontSee('321.78');
        $this->get(route('shop.catalog', ['q' => 'USB1', 'capacity' => 16, 'available' => 1]))->assertOk()->assertSee('1 modelos');
        $this->get(route('shop.product', $product->slug))->assertOk()->assertSee('Desde 50 piezas')->assertDontSee('321.78')->assertDontSee('<script>alert', false);
        $this->get(route('shop.sitemap'))->assertOk()->assertSee($product->slug);
        $this->get(route('shop.info'))->assertOk();
        $category = ProductCategory::create(['name' => 'Privada', 'slug' => 'privada', 'is_published' => false]);
        $product->update(['product_category_id' => $category->id]);
        $this->get(route('shop.product', $product->slug))->assertNotFound();
        $this->get(route('shop.sitemap'))->assertDontSee($product->slug);
    }

    public function test_cart_validation_public_idempotence_and_admin_conversion(): void
    {
        $product = $this->fixture();
        $product->update(['is_published' => true]);
        $variant = ProductVariant::where('sku', 'USB1-A')->sole();
        $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 49])->assertSessionHasErrors('quantity');
        $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 501])->assertSessionHasErrors('quantity');
        $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 50])->assertRedirect(route('shop.request'));
        $this->get(route('shop.request'))->assertOk()->assertSee('50 piezas');
        $data = ['name' => 'Cliente público de prueba', 'email' => 'cliente@example.test', 'phone' => '5555555555', 'request_key' => session('quote_request_key'), 'website' => ''];
        $this->post(route('shop.request.submit'), $data)->assertRedirect()->assertSessionHas('request_received');
        $this->post(route('shop.request.submit'), $data)->assertRedirect();
        $this->assertDatabaseCount('quote_requests', 1);
        $request = QuoteRequest::sole();
        $this->assertArrayNotHasKey('supplier_price_minor', $request->items[0]);
        $this->post(route('shop.request.submit'), array_merge($data, ['name' => 'Otra persona']))->assertStatus(409);
        $this->actingAsAdmin();
        $this->get(route('admin.quote-requests.index'))->assertOk()->assertSee('Cliente público de prueba');
        $this->post(route('admin.quote-requests.convert', $request))->assertRedirect();
        $this->post(route('admin.quote-requests.convert', $request))->assertRedirect();
        $this->assertDatabaseCount('quotes', 1);
        $this->assertSame('draft', Quote::sole()->status);
        $this->assertSame(0, Quote::sole()->totals['total']);
        $this->assertSame('converted', $request->fresh()->status);
    }

    public function test_inventory_change_and_honeypot_block_submission(): void
    {
        $product = $this->fixture();
        $product->update(['is_published' => true]);
        $variant = ProductVariant::where('sku', 'USB1-A')->sole();
        $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 50]);
        $data = ['name' => 'Prueba', 'email' => 'cliente@example.test', 'phone' => '5555555555', 'request_key' => session('quote_request_key')];
        $this->post(route('shop.request.submit'), $data + ['website' => 'bot'])->assertSessionHasErrors('website');
        $variant->update(['stock' => 0]);
        $this->post(route('shop.request.submit'), $data)->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('quote_requests', 0);
    }
}
