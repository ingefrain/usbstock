<?php

namespace Tests\Unit;

use App\Commerce\Api\QuotePresenter;
use App\Commerce\Api\QuoteUnavailable;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuoteApiPresenterTest extends TestCase
{
    public function test_it_projects_the_issued_snapshot_and_net_persisted_amounts_without_queries(): void
    {
        [$quote, $version] = $this->records();
        DB::listen(fn () => $this->fail('The presenter must not query the database.'));
        $quote->customer = ['name' => 'Borrador modificado'];
        $quote->totals = ['total' => 1];
        $quote->folio = 'BORRADOR';

        $data = $this->present($quote, $version);

        $this->assertSame(['id', 'folio', 'source', 'dates', 'customer', 'items', 'unlisted_items', 'items_html',
            'sale_amount_available', 'sale_amount_unavailable_reason', 'amounts_basis', 'amounts_recorded_at', 'amounts', 'archived', 'comments', 'pdf'], array_keys($data));
        $this->assertSame(1700000001, $data['id']);
        $this->assertSame('USB-2026-EJEMPLO', $data['folio']);
        $this->assertSame(['system' => 'USB Stock', 'environment' => 'local'], $data['source']);
        $this->assertSame('2026-11-23T12:00:00-06:00', $data['amounts_recorded_at']);
        $this->assertSame($data['amounts_recorded_at'], $data['dates']['elaborated_at']);
        $this->assertSame('Contacto ficticio', $data['customer']['name']);
        $this->assertSame(77, $data['customer']['id']);
        $this->assertNull($data['customer']['secondary_phone']);
        $this->assertNull($data['items'][0]['id']);
        $this->assertNull($data['items'][0]['variant_sku']);
        $this->assertSame('VAR-FICTICIA', $data['items'][0]['sku']);
        $this->assertSame(['currency' => 'MXN', 'sale_amount' => 1099.0, 'shipping' => 50.01, 'tax' => 176.02, 'total' => 1325.03], $data['amounts']);
        $this->assertSame('Observación conservada', $data['comments']);
        $this->assertTrue($data['sale_amount_available']);
        $this->assertNull($data['sale_amount_unavailable_reason']);
        $this->assertSame('last_confirmed_quote_send', $data['amounts_basis']);
        $this->assertFalse($data['archived']);
        $this->assertSame([], $data['unlisted_items']);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertStringContainsString('"sale_amount":1099.0', $encoded);
        foreach (['provider_secret_marker', 'margin_secret_marker', 'issuer_secret_marker', 'photo_secret_marker'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_html_keeps_six_columns_escapes_content_and_uses_stored_line_totals(): void
    {
        [$quote, $version] = $this->records();
        $snapshot = $version->snapshot;
        $snapshot['items'][0]['product']['name'] = '<script>alert("x")</script> Á & Ω';
        $snapshot['items'][0]['product']['description'] = '<img src=x onerror="alert(1)">';
        $snapshot['items'][0]['printing'] = '<svg onload="alert(2)">';
        // Deliberately different from quantity × price: presentation must not recalculate it.
        $snapshot['items'][0]['unit_minor'] = 999;
        $version->snapshot = $snapshot;

        $html = $this->present($quote, $version)['items_html'];
        $this->assertSame(6, substr_count($html, '<td>'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Á &amp; Ω', $html);
        $this->assertStringContainsString('$1,100.11', $html);
        $this->assertStringContainsString('Descuento: $1.11', $html);
        $this->assertStringContainsString('$9.99', $html);
    }

    public function test_optional_values_and_special_items_are_preserved_without_catalog_dependencies(): void
    {
        [$quote, $version] = $this->records();
        $snapshot = $version->snapshot;
        $snapshot['client_id'] = null;
        $snapshot['customer'] = ['name' => 'Ejemplo', 'email' => null, 'company' => '   '];
        $snapshot['source_request'] = null;
        $snapshot['items'][0]['product']['sku'] = null;
        $snapshot['items'][0]['product']['name'] = 'Producto especial sin catálogo';
        $snapshot['unlisted_items'] = [['id' => null, 'name' => 'Referencia especial', 'description' => null, 'color' => null]];
        $version->snapshot = $snapshot;

        $data = $this->present($quote, $version);
        $this->assertNull($data['customer']['id']);
        $this->assertNull($data['customer']['company']);
        $this->assertNull($data['customer']['email']);
        $this->assertNull($data['comments']);
        $this->assertNull($data['items'][0]['sku']);
        $this->assertSame('Producto especial sin catálogo', $data['items'][0]['name']);
        $this->assertSame('Referencia especial', $data['unlisted_items'][0]['name']);
        $this->assertNull($data['items_html']);
    }

    public function test_missing_html_only_fields_do_not_invent_amounts_or_discard_the_snapshot(): void
    {
        [$quote, $version] = $this->records();
        $snapshot = $version->snapshot;
        unset($snapshot['totals']['line_totals'], $snapshot['items'][0]['unit_minor']);
        $version->snapshot = $snapshot;

        $data = $this->present($quote, $version);
        $this->assertNull($data['items_html']);
        $this->assertSame(1099.0, $data['amounts']['sale_amount']);
    }

    public function test_multiple_rows_keep_order_and_only_explicit_persisted_identifiers(): void
    {
        [$quote, $version] = $this->records();
        $snapshot = $version->snapshot;
        $snapshot['items'][] = ['id' => 88, 'product' => ['sku' => 'OTRO-MODELO', 'variant_sku' => 'OTRA-VARIANTE', 'name' => 'Segundo producto'],
            'quantity' => 1, 'printing' => null, 'unit_minor' => 3, 'printing_minor' => 0, 'discount_minor' => 0];
        $snapshot['totals']['subtotal'] += 3;
        $snapshot['totals']['total'] += 3;
        $snapshot['totals']['line_totals'][] = 3;
        $version->snapshot = $snapshot;

        $data = $this->present($quote, $version);
        $this->assertSame(['VAR-FICTICIA', 'OTRO-MODELO'], array_column($data['items'], 'sku'));
        $this->assertNull($data['items'][0]['id']);
        $this->assertSame(88, $data['items'][1]['id']);
        $this->assertSame('OTRA-VARIANTE', $data['items'][1]['variant_sku']);
        $this->assertSame(12, substr_count($data['items_html'], '<td>'));
        $this->assertSame(1099.03, $data['amounts']['sale_amount']);
        $this->assertSame(176.02, $data['amounts']['tax']);
    }

    public function test_legitimate_zero_totals_remain_numbers(): void
    {
        [$quote, $version] = $this->records();
        $snapshot = $version->snapshot;
        $snapshot['totals'] = ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'shipping' => 0, 'total' => 0, 'currency' => 'MXN'];
        $version->snapshot = $snapshot;

        $this->assertSame(['currency' => 'MXN', 'sale_amount' => 0.0, 'shipping' => 0.0, 'tax' => 0.0, 'total' => 0.0], $this->present($quote, $version)['amounts']);
    }

    public function test_invalid_persisted_shapes_dates_and_amounts_are_unavailable(): void
    {
        $changes = [
            ['totals.tax', '17602'], ['totals.total', null], ['totals.shipping', -1],
            ['totals.discount', 999999], ['totals.total', 132504], ['totals.currency', 'USD'],
            ['totals.subtotal', PHP_INT_MAX], ['issued_at', '2026-02-30T12:00:00+00:00'],
            ['issued_at', 'tomorrow'], ['issued_at', null], ['customer', 'invalid'],
            ['items.0.quantity', '50'], ['items.0.product.name', 42], ['items.0.product.name', "\xB1\x31"],
            ['unlisted_items', 'invalid'], ['unlisted_items', null], ['source_request', 'invalid'], ['client_id', '77'], ['version', 2],
        ];
        foreach ($changes as [$path, $value]) {
            [$quote, $version] = $this->records();
            $snapshot = $version->snapshot;
            data_set($snapshot, $path, $value);
            // Invalid UTF-8 cannot be put through Eloquent's JSON cast; use a custom read projection below.
            if ($path === 'items.0.product.name' && $value === "\xB1\x31") {
                $version = new class($snapshot) extends QuoteVersion
                {
                    public function __construct(private array $rawSnapshot = [])
                    {
                        parent::__construct();
                        $this->forceFill(['quote_id' => 7, 'version' => 1]);
                    }

                    public function getSnapshotAttribute(): array
                    {
                        return $this->rawSnapshot;
                    }
                };
            } else {
                $version->snapshot = $snapshot;
            }
            try {
                $this->present($quote, $version);
                $this->fail('Invalid snapshot accepted: '.$path);
            } catch (QuoteUnavailable $error) {
                $this->assertSame('Cotización no encontrada', $error->getMessage());
            }
        }
    }

    public function test_a_version_of_another_quote_is_not_presented(): void
    {
        [$quote, $version] = $this->records();
        $version->quote_id = 8;
        $this->expectException(QuoteUnavailable::class);
        $this->present($quote, $version);
    }

    private function present(Quote $quote, QuoteVersion $version): array
    {
        config(['quote_api.environment' => 'local']);

        return app(QuotePresenter::class)->present($quote, $version, 1700000001, ['available' => false, 'url' => null]);
    }

    private function records(): array
    {
        $quote = new Quote;
        $quote->id = 7;
        $quote->created_at = '2026-11-20 18:00:00';
        $version = new QuoteVersion(['quote_id' => 7, 'version' => 1, 'snapshot' => [
            'version' => 1, 'folio' => 'USB-2026-EJEMPLO', 'issued_at' => '2026-11-23T18:00:00+00:00',
            'client_id' => 77, 'customer' => ['name' => 'Contacto ficticio', 'company' => null, 'email' => 'fixture@example.invalid', 'phone' => null],
            'source_request' => ['message' => 'Observación conservada'], 'terms' => 'Condiciones, no comentarios',
            'issuer' => ['secret' => 'issuer_secret_marker'],
            'items' => [['product' => ['sku' => 'VAR-FICTICIA', 'name' => 'Producto de ejemplo', 'description' => null, 'color' => 'PL'],
                'quantity' => 50, 'printing' => 'Grabado', 'unit_minor' => 2000, 'printing_minor' => 200, 'discount_minor' => 111,
                'pricing' => ['cost' => 'provider_secret_marker', 'margin' => 'margin_secret_marker'], 'photo' => ['path' => 'photo_secret_marker']]],
            'totals' => ['subtotal' => 110011, 'discount' => 111, 'tax' => 17602, 'shipping' => 5001, 'total' => 132503, 'currency' => 'MXN', 'line_totals' => [110011]],
        ]]);

        return [$quote, $version];
    }
}
