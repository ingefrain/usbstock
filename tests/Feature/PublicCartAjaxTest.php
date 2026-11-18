<?php

namespace Tests\Feature;

use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicCartAjaxTest extends TestCase
{
    use RefreshDatabase;

    private function variants(int $count = 2)
    {
        Storage::fake('local');
        $rows = collect(range(1, $count))->map(fn ($i) => "AJAX$i-A,500,Memoria ficticia,321.78")->implode("\n");
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n".$rows, 'fixture.csv', null), null);
        Product::query()->update(['is_published' => true]);

        return ProductVariant::orderBy('id')->get();
    }

    private function add(ProductVariant $variant, int $quantity, int $revision, array $extra = [])
    {
        return $this->postJson(route('shop.cart.add'), $extra + ['variant' => $variant->uuid, 'quantity' => $quantity, 'revision' => $revision]);
    }

    public function test_absolute_updates_counts_idempotence_and_private_fragment(): void
    {
        [$a, $b] = $this->variants();
        $first = $this->add($a, 50, 0)->assertOk()->assertJsonPath('selection.line_count', 1)->assertJsonPath('selection.piece_count', 50)
            ->assertJsonPath('item.variant', $a->uuid)->assertJsonPath('item.quantity', 50)->assertJsonPath('selection.revision', 1);
        $key = $first->json('selection.request_key');
        $this->add($a, 50, 0)->assertOk()->assertJsonPath('selection.revision', 1)->assertJsonPath('selection.request_key', $key);
        $this->add($a, 80, 1)->assertOk()->assertJsonPath('selection.line_count', 1)->assertJsonPath('selection.piece_count', 80);
        $this->add($b, 50, 2, ['printing' => '<script>private()</script>'])->assertOk()->assertJsonPath('selection.line_count', 2)->assertJsonPath('selection.piece_count', 130);
        $this->getJson(route('shop.cart.state'), ['X-Selection-View' => 'review'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('321.78')->assertDontSee('supplier_price_minor')->assertDontSee('<script>private()', false);
        $this->assertSame(500, $a->fresh()->stock);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_stale_conflicts_stock_and_validation_do_not_clear_selection(): void
    {
        [$a, $b] = $this->variants();
        $this->add($a, 50, 0)->assertOk();
        $this->add($b, 50, 0)->assertStatus(409)->assertJsonPath('selection.line_count', 1);
        $this->add($a, 501, 1)->assertStatus(409)->assertJsonPath('success', false);
        $this->add($a, 49, 1)->assertUnprocessable();
        $this->add($a, 50, 1, ['printing' => ['bad']])->assertUnprocessable();
        $this->postJson(route('shop.cart.add'), ['variant' => $a->uuid, 'quantity' => 50])->assertUnprocessable();
        $b->product->update(['is_published' => false]);
        $this->add($b, 50, 1)->assertStatus(409);
        $this->assertSame([$a->id => 50], session('commerce_cart'));
        $this->assertSame(1, session('commerce_cart_revision'));
        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_removal_is_uuid_based_idempotent_and_native_forms_still_work(): void
    {
        [$a, $b] = $this->variants();
        $this->add($a, 50, 0)->assertOk();
        $this->deleteJson(route('shop.cart.remove', $b->id), ['revision' => 1])->assertOk()->assertJsonPath('item.variant', null)->assertJsonPath('selection.revision', 1);
        $this->add($b, 60, 1)->assertOk();
        $this->deleteJson(route('shop.cart.remove', $a->uuid), ['revision' => 1])->assertStatus(409);
        $this->deleteJson(route('shop.cart.remove', $a->uuid), ['revision' => 2])->assertOk()->assertJsonPath('selection.piece_count', 60);
        $this->deleteJson(route('shop.cart.remove', $a->uuid), ['revision' => 2])->assertOk()->assertJsonPath('selection.revision', 3);
        $this->from(route('shop.request'))->delete(route('shop.cart.remove', $b->id))->assertRedirect(route('shop.request'));
        $this->get(route('shop.request'))->assertOk()->assertSee('Tu selección está vacía');
        $this->post(route('shop.cart.add'), ['variant' => $a->uuid, 'quantity' => 70])->assertRedirect(route('shop.request'));
        $this->assertSame([$a->id => 70], session('commerce_cart'));
    }

    public function test_fifty_line_limit_allows_replacement_without_lifting_quantity_rules(): void
    {
        $variants = $this->variants(51);
        $cart = $variants->take(50)->mapWithKeys(fn ($variant) => [$variant->id => 50])->all();
        $this->withSession(['commerce_cart' => $cart, 'commerce_cart_revision' => 7]);
        $this->add($variants->last(), 50, 7)->assertUnprocessable();
        $this->add($variants->first(), 75, 7)->assertOk()->assertJsonPath('selection.line_count', 50)->assertJsonPath('selection.piece_count', 2525);
        $this->add($variants->first(), 1000001, 8)->assertUnprocessable();
    }

    public function test_consecutive_requests_keep_the_receipt_until_a_valid_new_selection(): void
    {
        [$a, $b] = $this->variants();
        $this->add($a, 50, 0, ['printing' => 'Un color'])->assertOk();
        $customer = ['name' => 'Prueba AJAX', 'email' => 'ajax@example.test', 'phone' => '5555555555', 'has_seller' => 1, 'seller_name' => 'Asesor de prueba'];
        $data = $customer + ['request_key' => session('quote_request_key')];
        $this->post(route('shop.request.submit'), $data)->assertRedirect(route('shop.request.received'));
        $this->post(route('shop.request.submit'), $data)->assertRedirect();
        $this->getJson(route('shop.cart.state'))->assertJsonPath('selection.line_count', 0)->assertJsonPath('selection.revision', 2);
        $this->add($b, 501, 2)->assertStatus(409);
        $this->assertTrue(session()->has('quote_request_receipt'));
        $this->deleteJson(route('shop.cart.remove', $a->uuid), ['revision' => 2])->assertOk()->assertJsonPath('item.variant', null)->assertJsonPath('selection.revision', 2);
        $this->add($b, 80, 2)->assertOk()->assertJsonPath('selection.line_count', 1);
        $this->assertFalse(session()->has('quote_request_receipt'));
        $this->assertSame([], session('commerce_cart_printing'));
        $this->post(route('shop.request.submit'), $customer + ['request_key' => session('quote_request_key')])->assertRedirect();
        $this->assertDatabaseCount('quote_requests', 2);
        $this->assertSame('Un color', QuoteRequest::orderBy('id')->first()->items[0]['printing']);
    }

    public function test_real_csrf_mismatch_is_a_safe_419_and_session_requests_are_locked(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['_token' => 'expected']);
        $this->postJson(route('shop.cart.add'), ['_token' => 'wrong'])->assertStatus(419)->assertJsonPath('success', false)->assertJsonPath('message', 'Tu sesión caducó. Actualiza la página antes de continuar.');
        $this->assertTrue(app('session')->shouldBlock());
        $this->assertSame(60, app('session')->defaultRouteBlockLockSeconds());
    }

    public function test_server_errors_are_localized_without_debug_details(): void
    {
        foreach ([401, 403, 429, 500, 503] as $status) {
            Route::post('/test-cart-error/'.$status, fn () => abort($status, 'SQL password internal traceback'))->name('shop.cart.probe.'.$status);
            $this->postJson('/test-cart-error/'.$status, [])->assertStatus($status)->assertJsonPath('success', false)
                ->assertDontSee('SQL')->assertDontSee('password')->assertDontSee('traceback');
        }
    }
}
