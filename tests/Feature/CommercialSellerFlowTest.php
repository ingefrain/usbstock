<?php

namespace Tests\Feature;

use App\Commerce\PublicQuoteRequests;
use App\Commerce\QuoteCalculator;
use App\Commerce\QuoteService;
use App\Commerce\SellerPreference;
use App\Commerce\SupplierSync;
use App\Models\CommercialClient;
use App\Models\CommercialSeller;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommercialSellerFlowTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function selection(): array
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nUSB1-A,500,Descripción histórica,10.00\nUSB2-B,600,Segundo modelo,12.00", 'fixture.csv', null), null);
        Product::query()->update(['is_published' => true]);
        $variants = ProductVariant::orderBy('id')->get();
        foreach ($variants as $v) {
            $this->post(route('shop.cart.add'), ['variant' => $v->uuid, 'quantity' => 50, 'printing' => 'Logotipo a una tinta'])->assertRedirect();
        }
        // Adding the same variant replaces its quantity, never duplicates a line.
        $this->post(route('shop.cart.add'), ['variant' => $variants[0]->uuid, 'quantity' => 75])->assertRedirect();
        $this->assertCount(2, session('commerce_cart'));

        return ['name' => 'Cliente de prueba', 'email' => 'cliente@example.test', 'phone' => '5555555555', 'company' => 'Empresa de prueba', 'country' => 'México', 'message' => 'Observaciones conservadas', 'has_seller' => 1, 'seller_name' => ' Josè Luis ', 'request_key' => session('quote_request_key')];
    }

    public function test_literal_names_multi_selection_and_public_tampering_cannot_associate_a_seller(): void
    {
        $data = $this->selection();
        $seller = CommercialSeller::create(['name' => 'José Luis', 'is_active' => true]);
        $this->get(route('shop.request'))->assertOk()->assertSee('autocomplete="off"', false)->assertDontSee('<datalist', false)->assertDontSee('name="seller_id"', false);
        $response = $this->post(route('shop.request.submit'), $data + ['seller_id' => $seller->id, 'unit_price' => '1']);
        $response->assertRedirect(route('shop.request.received'))->assertCookie(SellerPreference::COOKIE);
        $request = QuoteRequest::sole();
        $this->assertSame(' Josè Luis ', $request->seller_name);
        $this->assertNull($request->seller_id);
        $this->assertTrue($request->has_seller);
        $this->assertSame('Observaciones conservadas', $request->message);
        $this->assertSame([75, 50], array_column($request->items, 'quantity'));
        $this->assertSame('Logotipo a una tinta', $request->items[0]['printing']);
        $this->assertArrayNotHasKey('unit_price', $request->items[0]);
        $cookie = $response->getCookie(SellerPreference::COOKIE);
        $this->assertSame(' Josè Luis ', json_decode($cookie->getValue(), true)['name']);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString('Josè', $response->getCookie(SellerPreference::COOKIE, false)->getValue());
        $this->post(route('shop.request.submit'), $data)->assertRedirect();
        $this->assertDatabaseCount('quote_requests', 1);
        $this->get(route('shop.request.received'))->assertOk()->assertSee(substr($request->uuid, 0, 8));
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_cookie_absence_expiry_replacement_and_no_seller_clear(): void
    {
        $data = $this->selection();
        $preference = app(SellerPreference::class);
        $this->assertNull($preference->read(Request::create('/')));
        foreach (['José Luis', 'Josè Luis', 'JL'] as $name) {
            $payload = json_encode(['name' => $name, 'expires' => now()->addDay()->timestamp]);
            $this->withCookie(SellerPreference::COOKIE, $payload)->get(route('shop.request'))->assertOk()->assertSee('value="'.$name.'"', false);
        }
        $this->withCookie(SellerPreference::COOKIE, json_encode(['name' => 'Expired', 'expires' => now()->subSecond()->timestamp]))->get(route('shop.request'))->assertOk()->assertDontSee('value="Expired"', false);
        $response = $this->post(route('shop.request.submit'), array_replace($data, ['seller_name' => 'JL']));
        $this->assertSame('JL', json_decode($response->getCookie(SellerPreference::COOKIE)->getValue(), true)['name']);
        $this->post(route('shop.request.restart'))->assertRedirect();
        $this->assertEmpty(session('commerce_cart', []));
        $v = ProductVariant::first();
        $this->post(route('shop.cart.add'), ['variant' => $v->uuid, 'quantity' => 50]);
        $this->post(route('shop.request.submit'), array_replace($data, ['request_key' => session('quote_request_key'), 'has_seller' => 0, 'seller_name' => 'Old cookie name']))->assertRedirect()->assertCookieExpired(SellerPreference::COOKIE);
        $latest = QuoteRequest::latest('id')->first();
        $this->assertFalse($latest->has_seller);
        $this->assertNull($latest->seller_name);
        $this->assertNull($latest->seller_id);
        $this->delete(route('shop.seller.forget'))->assertRedirect()->assertCookieExpired(SellerPreference::COOKIE);
        config(['commerce.seller_cookie_days' => 7]);
        $cookie = $preference->cookie(Request::create('https://localhost/'), 'JL');
        $this->assertTrue($cookie->isSecure());
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 2);
    }

    public function test_costs_association_conversion_revision_snapshot_and_letter_pdf_preserve_origin(): void
    {
        $data = $this->selection();
        $this->post(route('shop.request.submit'), $data)->assertRedirect();
        $request = QuoteRequest::sole();
        $this->actingAsAdmin();
        $seller = CommercialSeller::create(['name' => 'José Luis · Prueba', 'is_active' => true]);
        $this->post(route('admin.quote-requests.seller', $request), ['seller_id' => $seller->id, 'revision' => 1, 'confirm' => 1])->assertRedirect();
        $this->assertSame(' Josè Luis ', $request->fresh()->seller_name);
        $this->assertSame(auth()->id(), $request->fresh()->seller_confirmed_by);
        $this->post(route('admin.quote-requests.seller', $request), ['seller_id' => null, 'revision' => 1, 'confirm' => 1])->assertStatus(409);
        $this->post(route('admin.quote-requests.convert', $request))->assertRedirect();
        $this->post(route('admin.quote-requests.convert', $request))->assertRedirect();
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('commercial_clients', 1);
        $quote = Quote::sole();
        $this->assertSame($seller->id, $quote->seller_id);
        $this->assertSame(' Josè Luis ', $quote->seller_original_name);
        $this->assertSame($request->id, $quote->sourceRequest->id);
        $this->assertSame(CommercialClient::sole()->id, $quote->commercial_client_id);
        $this->assertSame('México', $quote->customer['country']);
        $this->assertSame('Logotipo a una tinta', $quote->items[0]->printing);
        $this->get(route('admin.quote-requests.index', ['q' => 'Josè', 'status' => 'converted']))->assertOk()->assertSee('Continuar cotización');
        $this->get(route('admin.quotes.show', $quote))->assertOk()->assertSee('Solicitud '.substr($request->uuid, 0, 8));
        CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'Emisor de prueba', 'issuer_contact' => 'prueba@example.test']]);
        $input = ['revision' => $quote->revision, 'customer_name' => $data['name'], 'customer_email' => $data['email'], 'customer_country' => 'México', 'seller_id' => $seller->id, 'terms' => 'Condiciones de prueba', 'validity_days' => 30, 'tax_percent' => '16', 'shipping' => '50', 'items' => $quote->items->map(fn($i) => ['variant_id' => $i->product_variant_id, 'quantity' => $i->quantity, 'unit_price' => '20', 'printing_price' => '2', 'discount' => '0', 'printing' => $i->printing])->all()];
        Product::query()->update(['supplier_description' => 'Descripción posterior']);
        $service = app(QuoteService::class);
        $quote = $service->save($input, auth()->id(), $quote);
        $version = $service->issue($quote, $quote->revision, (string) Str::uuid(), auth()->id());
        $bytes = Storage::disk('local')->get($version->path);
        $this->assertSame($request->uuid, $version->snapshot['source_request']['uuid']);
        $this->assertSame(' Josè Luis ', $version->snapshot['seller_original_name']);
        $this->assertSame($seller->id, $version->snapshot['seller_id']);
        $this->assertSame('Descripción histórica', $version->snapshot['items'][0]['product']['description']);
        $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
        $pages = $reader->setSourceFile(Storage::disk('local')->path($version->path));
        for ($page=1; $page <= $pages; $page++) {
            $size = $reader->getTemplateSize($reader->importPage($page));
            $this->assertEqualsWithDelta(215.9, $size['width'], .02);
            $this->assertEqualsWithDelta(279.4, $size['height'], .02);
        }
        $quote->refresh();
        $service->revise($quote, $quote->revision, auth()->id());
        $this->assertSame($request->id, $quote->fresh()->sourceRequest->id);
        $this->assertSame($seller->id, $quote->fresh()->seller_id);
        $seller->update(['name' => 'Nombre posterior']);
        $this->assertSame('José Luis · Prueba', $version->fresh()->snapshot['seller']);
        $this->assertSame($bytes, Storage::disk('local')->get($version->path));
        $this->assertTrue(DB::table('admin_audit_events')->where('action', 'quote_request.seller_assigned')->exists());
    }

    public function test_directory_permissions_inactive_seller_and_no_automatic_assignment(): void
    {
        $data = $this->selection();
        $this->post(route('shop.request.submit'), array_replace($data, ['has_seller' => 0]));
        $request = QuoteRequest::sole();
        $this->actingAsAdmin();
        $seller = CommercialSeller::create(['name' => 'JL', 'is_active' => false]);
        $this->post(route('admin.quote-requests.seller', $request), ['seller_id' => $seller->id, 'revision' => 1, 'confirm' => 1])->assertSessionHasErrors('seller_id');
        $this->get(route('admin.sellers.index'))->assertOk();
        $this->post(route('admin.sellers.save'), ['name' => 'Nuevo asesor', 'is_active' => 1])->assertRedirect();
        $this->patchJson(route('admin.sellers.status', $seller), ['is_active' => 1])->assertOk()->assertJson(['success' => true, 'is_active' => true]);
        // A stale name form must not undo the separate status toggle.
        $this->post(route('admin.sellers.save', $seller), ['name' => 'Nombre actualizado', 'is_active' => 0])->assertRedirect();
        $this->assertTrue($seller->fresh()->is_active);
        $this->post(route('admin.quote-requests.convert', $request))->assertRedirect();
        $this->assertNull(Quote::sole()->seller_id);
        $this->assertSame('', Quote::sole()->seller);
        $this->actingAsAdmin($this->userWithRole('operator'));
        $this->get(route('admin.sellers.index'))->assertForbidden();
        $this->post(route('admin.sellers.save'), ['name' => 'Unauthorized', 'is_active' => 1])->assertForbidden();
        $this->post(route('admin.quote-requests.seller', $request), ['seller_id' => $seller->id, 'revision' => 2, 'confirm' => 1])->assertForbidden();
    }

    public function test_missing_seller_and_unsafe_text_are_rejected_without_suggesting_names(): void
    {
        $data = $this->selection();
        $this->post(route('shop.request.submit'), array_replace($data, ['seller_name' => '  ']))->assertSessionHasErrors('seller_name');
        $this->post(route('shop.request.submit'), array_replace($data, ['seller_name' => "JL\nInjected"]))->assertSessionHasErrors('seller_name');
        $this->post(route('shop.request.submit'), array_replace($data, ['seller_name' => '<script>alert(1)</script>']))->assertRedirect();
        $this->actingAsAdmin();
        $this->get(route('admin.quote-requests.index'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_production_shipping_vat_difference_is_explicit_without_changing_legacy_v1(): void
    {
        // codexv0 acciones.php:230-234 taxes shipping; codex secondary rule does not.
        $actual = (new QuoteCalculator)->calculate([['quantity' => 50, 'unit_minor' => 2000, 'printing_minor' => 200, 'discount_minor' => 0]], 1600, 5000);
        $productionTax = intdiv((110000 + 5000) * 1600 + 5000, 10000);
        $this->assertSame(17600, $actual['tax']);
        $this->assertSame(18400, $productionTax);
        $this->assertSame(800, $productionTax - $actual['tax']);
    }
}
