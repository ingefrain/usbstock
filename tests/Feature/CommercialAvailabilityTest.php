<?php

namespace Tests\Feature;

use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommercialAvailabilityTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function fixture(): void
    {
        Storage::fake('local');
        $rows = collect([0, 1, 49, 50, 51, 100])->map(fn ($stock) => "MIN$stock-A,$stock,Memoria ficticia,10")->implode("\n");
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n".$rows, 'minimum-fixture.csv', null), null);
        Product::query()->update(['is_published' => true]);
    }

    public function test_stock_boundaries_apply_to_all_public_surfaces_and_native_and_ajax_additions(): void
    {
        $this->fixture();
        $html = $this->getJson('/catalogo/bloques')->assertOk()->assertJsonPath('count', 3)->json('html');
        foreach ([0, 1, 49, 50, 51, 100] as $stock) {
            $variant = ProductVariant::with('product')->where('sku', "MIN$stock-A")->sole();
            $response = $this->get(route('shop.product', $variant->product->slug));
            if ($stock < 50) {
                $response->assertNotFound();
                $this->assertStringNotContainsString('data-product-id="'.$variant->product->uuid.'"', $html);
                $this->getJson('/catalogo/bloques?q=MIN'.$stock.'-A')->assertJsonPath('count', 0);
                $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 50, 'revision' => (int) session('commerce_cart_revision', 0)])
                    ->assertStatus(409)->assertJsonPath('availability.maximum_quantity', $stock)->assertJsonPath('availability.code', 'minimum_stock');
                $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 50])->assertSessionHasErrors('quantity');
            } else {
                $response->assertOk()->assertSee('value="50"', false);
                $this->getJson('/catalogo/bloques?q=MIN'.$stock.'-A')->assertJsonPath('count', 1);
                $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => $stock, 'revision' => (int) session('commerce_cart_revision', 0)])->assertOk();
                $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => $stock + 1, 'revision' => (int) session('commerce_cart_revision', 0)])
                    ->assertStatus(409)->assertJsonPath('availability.maximum_quantity', $stock);
                $this->assertSame($stock, session('commerce_cart')[$variant->id]);
            }
        }
        $this->assertDatabaseCount('product_variants', 6);
        $this->assertDatabaseCount('quote_requests', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_invalid_quantities_and_stock_changes_preserve_selection_and_original_request(): void
    {
        $this->fixture();
        $variant = ProductVariant::where('sku', 'MIN100-A')->sole();
        foreach ([-1, 0, 1, 10, 25, 49, 50.5, 'abc'] as $quantity) {
            $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => $quantity, 'revision' => 0])->assertUnprocessable();
            $this->post(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => $quantity])->assertSessionHasErrors('quantity');
        }
        $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 80, 'revision' => 0])->assertOk();
        $variant->update(['stock' => 51]);
        $this->postJson(route('shop.cart.add'), ['variant' => $variant->uuid, 'quantity' => 80, 'revision' => 1])->assertStatus(409)->assertJsonPath('availability.maximum_quantity', 51);
        $customer = ['name' => 'Prueba de mínimo', 'email' => 'minimum@example.test', 'phone' => '5555555555', 'has_seller' => 0, 'request_key' => session('quote_request_key')];
        $this->post(route('shop.request.submit'), $customer)->assertSessionHasErrors('cart');
        $this->assertSame(80, session('commerce_cart')[$variant->id]);
        $variant->update(['stock' => 100]);
        $this->post(route('shop.request.submit'), $customer)->assertRedirect(route('shop.request.received'));
        $request = QuoteRequest::sole();
        $original = $request->getAttributes();
        $variant->update(['stock' => 49]);
        $this->actingAsAdmin();
        $this->post(route('admin.quote-requests.convert', $request))->assertSessionHasErrors('cart');
        $this->assertSame($original, $request->fresh()->getAttributes());
        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('commercial_clients', 0);
    }

    public function test_multiple_variants_categories_recovery_and_editorial_independence(): void
    {
        $this->fixture();
        $product = Product::where('external_key', 'MIN50')->sole();
        $category = ProductCategory::create(['name' => 'Prueba', 'slug' => 'minimum-test', 'is_published' => true]);
        $product->categories()->attach($category, ['source' => 'fixture']);
        $low = $product->variants()->create(['sku' => 'MIN50-B', 'color_code' => 'B', 'supplier_description' => 'Variante ficticia', 'stock' => 49, 'provider_active' => true, 'is_enabled' => true, 'supplier_price_minor' => 100]);
        $disabled = $product->variants()->create(['sku' => 'MIN50-C', 'color_code' => 'C', 'supplier_description' => 'Variante ficticia', 'stock' => 100, 'provider_active' => true, 'is_enabled' => false, 'supplier_price_minor' => 100]);
        $this->get(route('shop.product', $product->slug))->assertOk()->assertDontSee($low->uuid)->assertDontSee($disabled->uuid);
        $this->get('/catalogo/categoria/minimum-test')->assertOk()->assertSee('MIN50')->assertDontSee('MIN100');
        $this->get('/catalogo')->assertOk()->assertDontSee('name="capacity"', false)->assertDontSee('name="available"', false);
        $this->actingAsAdmin();
        $this->get('/admin/products')->assertOk()->assertSee('MIN0')->assertSee('MIN49');
        $this->get('/admin/products?availability=empty')->assertOk()->assertSee('MIN0')->assertDontSee('MIN50');
        $this->get('/admin/products?availability=insufficient')->assertOk()->assertSee('MIN49')->assertDontSee('MIN50');
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nMIN50-A,49,Memoria actualizada de prueba,10", 'low.csv', null), null, false, true);
        $this->get(route('shop.product', $product->slug))->assertNotFound();
        $this->assertTrue($product->fresh()->is_published);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nMIN50-A,51,Memoria actualizada de prueba,10", 'recovered.csv', null), null, false, true);
        $this->get(route('shop.product', $product->slug))->assertOk();
        $this->assertTrue($product->fresh()->categories->contains($category));
        $product->update(['is_published' => false]);
        $this->get(route('shop.product', $product->slug))->assertNotFound();
    }
}
