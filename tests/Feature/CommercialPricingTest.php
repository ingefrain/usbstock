<?php

namespace Tests\Feature;

use App\Commerce\CommercialPricing;
use App\Commerce\LegacyPriceScales;
use App\Commerce\Money;
use App\Commerce\PublicQuoteRequests;
use App\Commerce\QuoteService;
use App\Commerce\SupplierSync;
use App\Models\AdminAuditEvent;
use App\Models\CommercialPriceScale;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommercialPricingTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    public function test_old_zero_draft_shows_all_proposals_on_load_without_writing_prices(): void
    {
        $this->prepare();
        $data = $this->data(false);
        $data['items'][0]['unit_price'] = '0';
        $quote = app(QuoteService::class)->save($data, auth()->id());
        $quote->items()->update(['pricing' => null]);
        $before = AdminAuditEvent::count();
        $this->get(route('admin.quotes.show', $quote))->assertOk()
            ->assertViewHas('proposals', fn ($p) => $p[0]['price'] === '94.50' && $p[0]['context']['cost_minor'] === 7000)
            ->assertSee('Aplicar propuestas a partidas en cero')->assertSee('Venta unitaria guardada')->assertSee('Diferencia contra guardado');
        $this->assertSame(0, $quote->fresh()->items->sole()->unit_minor);
        $this->assertNull($quote->fresh()->items->sole()->pricing);
        $this->assertSame(1, $quote->fresh()->revision);
        $this->assertSame($before, AdminAuditEvent::count());
    }

    public function test_batch_application_preserves_other_manual_prices_and_records_provenance(): void
    {
        $this->prepare();
        $data = $this->data(false);
        $manual = $data['items'][0];
        $manual['variant_id'] = ProductVariant::where('sku', 'MODEL-A')->sole()->id;
        $data['items'][0]['unit_price'] = '0';
        $data['items'][] = $manual;
        $quote = app(QuoteService::class)->save($data, auth()->id());
        $manualBefore = $quote->items->last()->pricing;
        $proposal = app(CommercialPricing::class)->proposal($this->variant(), 50);
        $data['items'][0] = array_replace($data['items'][0], ['unit_price' => $proposal['price'], 'pricing_action' => 'automatic', 'pricing_fingerprint' => $proposal['fingerprint']]);
        $this->put(route('admin.quotes.update', $quote), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([9450, 9900], $quote->fresh()->items->pluck('unit_minor')->all());
        $this->assertSame($manualBefore, $quote->fresh()->items->last()->pricing);
        $this->assertSame('automatic', $quote->fresh()->items->first()->pricing['mode']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'quote.pricing_saved', 'entity_id' => $quote->uuid, 'actor_id' => auth()->id()]);
        $this->put(route('admin.quotes.update', $quote), $data)->assertStatus(409);
        $this->assertSame(2, $quote->fresh()->revision);
    }

    public function test_one_obsolete_proposal_rolls_back_the_entire_multi_line_application(): void
    {
        $this->prepare();
        $data = $this->data(false);
        $data['items'][0]['unit_price'] = '0';
        $data['items'][] = array_replace($data['items'][0], ['variant_id' => ProductVariant::where('sku', 'MODEL-A')->sole()->id]);
        $quote = app(QuoteService::class)->save($data, auth()->id());
        foreach ($data['items'] as &$line) {
            $proposal = app(CommercialPricing::class)->proposal(ProductVariant::findOrFail($line['variant_id']), $line['quantity']);
            $line = array_replace($line, ['unit_price' => $proposal['price'], 'pricing_action' => 'automatic', 'pricing_fingerprint' => $proposal['fingerprint']]);
        }
        unset($line);
        ProductVariant::where('sku', 'MODEL-A')->update(['supplier_price_minor' => 6000]);
        $before = AdminAuditEvent::count();
        $this->put(route('admin.quotes.update', $quote), $data)->assertStatus(409);
        $this->assertSame([0, 0], $quote->fresh()->items->pluck('unit_minor')->all());
        $this->assertSame(1, $quote->fresh()->revision);
        $this->assertSame($before, AdminAuditEvent::count());
    }

    public function test_cost_panel_requires_costs_management_and_is_absent_on_issued_quotes(): void
    {
        $this->prepare();
        $admin = auth()->user();
        $quote = app(QuoteService::class)->save($this->data(), $admin->id);
        $this->actingAsAdmin($this->userWithPermissions(['quotes.view']));
        $this->get(route('admin.quotes.show', $quote))->assertOk()->assertViewHas('proposals', [])
            ->assertDontSee('data-pricing-panel', false)->assertDontSee('Procedencia del precio guardado');
        $this->getJson(route('admin.quotes.price-proposal', ['variant_id' => $this->variant()->id, 'quantity' => 50]))->assertForbidden();
        $this->actingAsAdmin($admin);
        $version = app(QuoteService::class)->issue($quote, 1, (string) Str::uuid(), $admin->id);
        $snapshot = $version->snapshot;
        $this->get(route('admin.quotes.show', $quote))->assertOk()->assertViewHas('proposals', [])->assertDontSee('data-pricing-panel', false);
        $this->assertSame($snapshot, $version->fresh()->snapshot);
        $this->assertSame($version->sha256, hash('sha256', Storage::disk('local')->get($version->path)));
    }

    private function reference(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Commerce/legacy-price-scales.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function prepare(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        app(LegacyPriceScales::class)->import($this->reference(), auth()->id());
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nMODEL-A,5000,Modelo de prueba,54\nMODEL-B,5000,Modelo de prueba,70", 'pricing-fixture.csv', null), null);
        Product::query()->update(['is_published' => true]);
        CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'Emisor QA', 'issuer_contact' => 'qa@example.test', 'terms' => 'Condiciones de prueba']]);
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::where('sku', 'MODEL-B')->sole();
    }

    private function data(bool $automatic = true): array
    {
        $v = $this->variant();
        $proposal = app(CommercialPricing::class)->proposal($v, 50);

        return ['revision' => 1, 'customer_name' => 'Cliente QA', 'customer_email' => 'qa@example.test', 'terms' => 'Condiciones de prueba', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '50',
            'items' => [['variant_id' => $v->id, 'quantity' => 50, 'unit_price' => $automatic ? $proposal['price'] : '99', 'printing_price' => '2', 'discount' => '100',
                'pricing_action' => $automatic ? 'automatic' : 'keep', 'pricing_fingerprint' => $automatic ? $proposal['fingerprint'] : null]]];
    }

    public function test_independent_mariadb_oracle_matches_all_26_real_cost_and_band_cases(): void
    {
        $this->prepare();
        $different = 0;
        foreach ($this->reference()['cases'] as $case) {
            $result = app(CommercialPricing::class)->calculate(Money::parse($case['variant_cost']), $case['quantity']);
            $this->assertTrue($result['available']);
            $this->assertSame($case['expected_variant_price'], $result['price'], $case['sku'].' x '.$case['quantity']);
            $this->assertSame($case['increment'] * 100, $result['basis']['increment_basis_points']);
            $this->assertSame('Escala heredada '.$case['scale_id'], $result['basis']['scale_name']);
            if ($case['variant_cost'] === $case['legacy_model_cost']) {
                $this->assertSame($case['expected_legacy_price'], $result['price']);
            } else {
                $different++;
            }
        }
        $this->assertSame(6, $different, 'Six reference cases document the approved own-variant-cost difference.');
    }

    public function test_exact_cent_boundaries_and_last_band_fallback(): void
    {
        $this->prepare();
        foreach ([[2000, 150, 1], [13, 23077, 2], [2000, 500, 2], [9901, 101, 3], [2000, 1000, 3], [3, 666667, 4], [100100, 1000000, 4]] as [$cost, $quantity, $band]) {
            $result = app(CommercialPricing::class)->calculate($cost, $quantity);
            $this->assertTrue($result['available']);
            $this->assertSame('Escala heredada '.$band, $result['basis']['scale_name']);
        }
        $this->assertSame('2.03', app(CommercialPricing::class)->calculate(150, 50)['price']);
        foreach ([[0, 50], [2000, 49], [2000, 1000001], [-1, 50], [100000001, 50]] as [$cost, $quantity]) {
            $this->assertFalse(app(CommercialPricing::class)->calculate($cost, $quantity)['available']);
        }
    }

    public function test_import_is_idempotent_and_preserves_manual_edits(): void
    {
        $this->prepare();
        $count = AdminAuditEvent::count();
        $this->assertSame(0, app(LegacyPriceScales::class)->import($this->reference(), auth()->id()));
        $this->assertSame($count, AdminAuditEvent::count());
        $scale = CommercialPriceScale::orderBy('id')->first();
        $this->post(route('admin.price-scales.save', $scale), ['name' => 'Ajustada por costos', 'lower' => '0', 'upper' => '3000', 'increment' => '36', 'is_active' => 0, 'revision' => 1, 'source_reference' => 'Autorización manual de prueba'])->assertRedirect();
        app(LegacyPriceScales::class)->import($this->reference(), auth()->id());
        $this->assertFalse($scale->fresh()->is_active);
        $this->assertSame(3600, $scale->fresh()->increment_basis_points);
        $this->assertDatabaseCount('commercial_price_scales', 4);
    }

    public function test_scale_authorization_validation_history_and_optimistic_concurrency(): void
    {
        $this->prepare();
        $this->get(route('admin.price-scales.index', ['cost' => '70', 'quantity' => 50]))->assertOk()->assertSee('94.50');
        $this->get(route('admin.price-scales.create'))->assertOk();
        $scale = CommercialPriceScale::first();
        $this->get(route('admin.price-scales.edit', $scale))->assertOk();
        $this->get(route('admin.price-scales.history', $scale))->assertOk()->assertSee('pricing.legacy_imported');
        $input = ['name' => 'Conflicto', 'lower' => '3000', 'upper' => '4000', 'increment' => '10', 'is_active' => 1, 'revision' => 1, 'source_reference' => 'Referencia autorizada de prueba'];
        $this->post(route('admin.price-scales.save'), $input)->assertSessionHasErrors('lower');
        foreach (['-1', '1.234', '1e3', '9999999999999999'] as $value) {
            $this->post(route('admin.price-scales.save'), array_replace($input, ['lower' => $value]))->assertSessionHasErrors('lower');
        }
        $url = route('admin.price-scales.status', $scale);
        $this->patchJson($url, ['revision' => 1, 'is_active' => false])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson($url, ['revision' => 1, 'is_active' => true])->assertStatus(409);
        $this->assertFalse($scale->fresh()->is_active);
        $this->patchJson($url, ['revision' => 2, 'is_active' => true])->assertOk()->assertJsonPath('revision', 3);
        $this->actingAsAdmin($this->userWithRole('operator'));
        $this->get(route('admin.price-scales.index'))->assertForbidden();
        $this->post(route('admin.price-scales.save'), $input)->assertForbidden();
        $this->getJson(route('admin.quotes.price-proposal', ['variant_id' => $this->variant()->id, 'quantity' => 50]))->assertForbidden();
    }

    public function test_gaps_disabled_scales_and_missing_cost_keep_manual_capture_available(): void
    {
        $this->prepare();
        CommercialPriceScale::where('source_key', 'usbstock2.productos_escalas:2')->update(['is_active' => false]);
        $this->assertFalse(app(CommercialPricing::class)->proposal($this->variant(), 50)['available']);
        $quote = app(QuoteService::class)->save($this->data(false), auth()->id());
        $this->assertSame(9900, $quote->items->sole()->unit_minor);
        $v = $this->variant();
        $v->update(['supplier_price_minor' => 0]);
        $this->assertFalse(app(CommercialPricing::class)->proposal($v, 50)['available']);
        $v->update(['supplier_price_minor' => 7000, 'stock' => 49]);
        $this->assertFalse(app(CommercialPricing::class)->proposal($v, 50)['available']);
        $this->post(route('admin.quotes.store'), $this->data(false))->assertSessionHasErrors('items');
    }

    public function test_conversion_uses_own_variant_cost_and_keeps_request_and_printing_separate(): void
    {
        $this->prepare();
        $request = app(PublicQuoteRequests::class)->submit(['name' => 'Cliente QA', 'email' => 'qa@example.test'], [$this->variant()->id => 50], 'Solicitud de prueba', (string) Str::uuid(), printing: [$this->variant()->id => 'Impresión pendiente']);
        $original = $request->items;
        $quote = app(PublicQuoteRequests::class)->convert($request, auth()->id());
        $line = $quote->items->sole();
        $this->assertSame(9450, $line->unit_minor);
        $this->assertSame(7000, $line->pricing['basis']['cost_minor']);
        $this->assertSame('automatic', $line->pricing['mode']);
        $this->assertSame(0, $line->printing_minor);
        $this->assertSame('Impresión pendiente', $line->printing);
        $this->assertSame($original, $request->fresh()->items);
        $this->assertSame($quote->id, app(PublicQuoteRequests::class)->convert($request, auth()->id())->id);
        $this->assertNotNull($quote->commercial_client_id);
    }

    public function test_quantity_change_keeps_price_and_manual_override_is_audited(): void
    {
        $this->prepare();
        $data = $this->data();
        $quote = app(QuoteService::class)->save($data, auth()->id());
        $data['items'][0]['pricing_action'] = 'keep';
        $data['items'][0]['quantity'] = 200;
        $quote = app(QuoteService::class)->save($data, auth()->id(), $quote);
        $this->assertSame(9450, $quote->items->sole()->unit_minor);
        $this->assertSame('retained', $quote->items->sole()->pricing['mode']);
        $this->assertSame('90.30', app(CommercialPricing::class)->proposal($this->variant(), 200)['price']);
        $data['revision'] = 2;
        $data['items'][0]['unit_price'] = '99';
        $data['items'][0]['pricing_reason'] = 'Acuerdo comercial de prueba';
        $quote = app(QuoteService::class)->save($data, auth()->id(), $quote);
        $this->assertSame('manual', $quote->items->sole()->pricing['mode']);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'quote.pricing_saved', 'entity_id' => $quote->uuid, 'actor_id' => auth()->id()]);
        $this->assertSame(9900, $quote->items->sole()->unit_minor);
    }

    public function test_stale_cost_scale_and_tampered_proposal_are_rejected_without_silent_repricing(): void
    {
        $this->prepare();
        $data = $this->data();
        $this->variant()->update(['supplier_price_minor' => 8000]);
        $this->post(route('admin.quotes.store'), $data)->assertStatus(409);
        $this->assertDatabaseCount('quotes', 0);
        $data = $this->data();
        $scale = CommercialPriceScale::where('source_key', 'usbstock2.productos_escalas:2')->sole();
        $scale->increment('revision');
        $this->post(route('admin.quotes.store'), $data)->assertStatus(409);
        $data = $this->data();
        $data['items'][0]['unit_price'] = '1';
        $this->post(route('admin.quotes.store'), $data)->assertStatus(409);
        $data = $this->data();
        $data['items'][0]['quantity'] = 49;
        $this->post(route('admin.quotes.store'), $data)->assertSessionHasErrors('items.0.quantity');
    }

    public function test_issued_snapshot_and_pdf_survive_cost_scale_and_revision_changes(): void
    {
        $this->prepare();
        $data = $this->data();
        $service = app(QuoteService::class);
        $quote = $service->save($data, auth()->id());
        $version = $service->issue($quote, 1, (string) Str::uuid(), auth()->id());
        $snapshot = $version->snapshot;
        $bytes = Storage::disk('local')->get($version->path);
        $this->assertSame(7000, $snapshot['items'][0]['pricing']['basis']['cost_minor']);
        $this->variant()->update(['supplier_price_minor' => 8000]);
        $this->put(route('admin.quotes.update', $quote), $data)->assertStatus(409);
        $service->revise($quote, 2, auth()->id());
        $data = $this->data();
        $data['revision'] = 3;
        $service->save($data, auth()->id(), $quote);
        $this->assertSame(10800, $quote->fresh()->items->sole()->unit_minor);
        $this->assertSame($snapshot, $version->fresh()->snapshot);
        $this->assertSame($bytes, Storage::disk('local')->get($version->path));
        $this->assertSame(hash('sha256', $bytes), $version->fresh()->sha256);
    }

    public function test_manual_price_does_not_require_an_active_scale_and_automatic_override_requires_reason(): void
    {
        $this->prepare();
        $data = $this->data();
        $quote = app(QuoteService::class)->save($data, auth()->id());
        $data['items'][0]['pricing_action'] = 'keep';
        $data['items'][0]['unit_price'] = '100';
        $this->put(route('admin.quotes.update', $quote), $data)->assertSessionHasErrors('items');
        $this->assertSame(1, $quote->fresh()->revision);
        CommercialPriceScale::query()->update(['is_active' => false]);
        $data['items'][0]['pricing_reason'] = 'Precio comercial autorizado de prueba';
        $this->put(route('admin.quotes.update', $quote), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(10000, $quote->fresh()->items->sole()->unit_minor);
        $data['revision'] = 2;
        $data['items'][0]['quantity'] = 100;
        $this->put(route('admin.quotes.update', $quote), $data)->assertRedirect();
        $this->assertSame(10000, $quote->fresh()->items->sole()->unit_minor);
    }
}
