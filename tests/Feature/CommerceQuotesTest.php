<?php

namespace Tests\Feature;

use App\Commerce\QuoteCalculator;
use App\Commerce\QuoteService;
use App\Commerce\SupplierSync;
use App\Models\AdminAuditEvent;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommerceQuotesTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nUSB1-A,500,Memoria de prueba,10.00", 'fixture.csv', null), null);
        CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'Emisor ficticio de prueba', 'issuer_contact' => 'prueba@example.test']]);

        return ['revision' => 1, 'customer_name' => 'Cliente de prueba', 'customer_email' => 'cliente@example.test', 'seller' => 'Vendedor de prueba', 'terms' => 'Condición ficticia de prueba', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '50.00', 'items' => [['variant_id' => ProductVariant::sole()->id, 'quantity' => 50, 'unit_price' => '20.00', 'printing_price' => '2.00', 'discount' => '100.00', 'printing' => 'Texto de prueba']]];
    }

    public function test_legacy_calculation_rounding_and_invalid_limits(): void
    {
        $calculator = new QuoteCalculator;
        $result = $calculator->calculate([['quantity' => 50, 'unit_minor' => 2000, 'printing_minor' => 200, 'discount_minor' => 10000]], 1600, 5000);
        $this->assertSame(['subtotal' => 110000, 'discount' => 10000, 'tax' => 17600, 'shipping' => 5000, 'total' => 122600, 'currency' => 'MXN', 'line_totals' => [110000]], $result);
        $this->assertSame(1, $calculator->calculate([['quantity' => 1, 'unit_minor' => 5, 'printing_minor' => 0, 'discount_minor' => 0]], 1000, 0)['tax']);
        foreach ([['quantity' => 0, 'unit_minor' => 1, 'printing_minor' => 0, 'discount_minor' => 0], ['quantity' => 1, 'unit_minor' => 1, 'printing_minor' => 0, 'discount_minor' => 2], ['quantity' => 1000000, 'unit_minor' => 100000000, 'printing_minor' => 0, 'discount_minor' => 0]] as $line) {
            try {
                $calculator->calculate([$line], 1600, 0);
                $this->fail('Límite inválido');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function schemaVariants(): array
    {
        return ['current schema' => [false], 'before API migrations' => [true]];
    }

    private function withoutApiSchema(): void
    {
        // This destructive fixture is restricted to the in-memory test connection.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::drop('quote_public_identifiers');
        Schema::table('commerce_locks', fn (Blueprint $table) => $table->dropColumn('public_quote_sequence'));
        $this->assertFalse(Schema::hasTable('quote_public_identifiers'));
        $this->assertFalse(Schema::hasColumn('commerce_locks', 'public_quote_sequence'));
    }

    #[DataProvider('schemaVariants')]
    public function test_admin_draft_issue_revision_download_and_immutable_history(bool $beforeApiMigrations): void
    {
        $this->assertFalse(config('quote_api.enabled'));
        if ($beforeApiMigrations) {
            $this->withoutApiSchema();
        }
        $data = $this->fixture();
        $data['tax_percent'] = '16.00';
        $this->get(route('admin.quotes.create'))->assertOk();
        $this->post(route('admin.quotes.store'), $data)->assertRedirect();
        $quote = Quote::sole();
        $this->assertSame(122600, $quote->totals['total']);
        $this->get(route('admin.quotes.show', $quote))->assertOk();
        $key = (string) Str::uuid();
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 1, 'request_key' => $key, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $version = QuoteVersion::sole();
        $bytes = Storage::disk('local')->get($version->path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(hash('sha256', $bytes), $version->sha256);
        $reader = new Mpdf(['tempDir' => storage_path('framework/testing/mpdf')]);
        $this->assertGreaterThanOrEqual(1, $reader->setSourceFile(Storage::disk('local')->path($version->path)));
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 1, 'request_key' => $key, 'confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('quote_versions', 1);
        $this->get(route('admin.quotes.download', [$quote, $version]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->put(route('admin.quotes.update', $quote), $data)->assertStatus(409);
        Product::sole()->update(['supplier_description' => 'Cambió proveedor']);
        ProductVariant::sole()->update(['supplier_price_minor' => 999999]);
        $this->assertSame('Memoria de prueba', $version->fresh()->snapshot['items'][0]['product']['description']);
        $this->post(route('admin.quotes.revise', $quote), ['revision' => 2, 'confirm' => 1])->assertRedirect();
        $data['revision'] = 3;
        $data['items'][0]['unit_price'] = '25.00';
        $this->put(route('admin.quotes.update', $quote), $data)->assertRedirect();
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 4, 'request_key' => (string) Str::uuid(), 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('quote_versions', 2);
        $this->assertSame($bytes, Storage::disk('local')->get($version->path));
        $this->assertSame(122600, $version->fresh()->snapshot['totals']['total']);
        $this->get(route('admin.quotes.index'))->assertOk();
        $this->get(route('admin.clients.index'))->assertOk();
        $this->get(route('admin.commerce.settings'))->assertOk();
        $this->actingAsAdmin($this->userWithRole('operator'))->get(route('admin.quotes.download', [$quote, $version]))->assertForbidden();
        $this->assertEmpty(glob(storage_path('app/private/pdf-temp/*'), GLOB_ONLYDIR));
        $this->assertSame('issued', $quote->fresh()->status);
        $this->assertSame(2, AdminAuditEvent::where('action', 'quote.issued')->where('entity_id', $quote->uuid)->count());
        if (! $beforeApiMigrations) {
            $this->assertDatabaseCount('quote_public_identifiers', 0);
            $this->assertSame(2999, (int) DB::table('commerce_locks')->where('id', 1)->value('public_quote_sequence'));
        }
    }

    public function test_disabled_api_rejects_reads_before_accessing_unmigrated_schema(): void
    {
        $this->assertFalse(config('quote_api.enabled'));
        $this->withoutApiSchema();
        $token = bin2hex(random_bytes(32));
        config(['quote_api.bearer_token' => $token, 'quote_api.pdf_enabled' => true]);

        $this->getJson('/api/v1/cotizaciones/3000', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHORIZED');
        $this->get('/api/v1/documentos/synthetic-capability')
            ->assertNotFound()->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
        $this->get(route('admin.login'))->assertOk();
        $this->get(route('shop.home'))->assertOk();
    }

    public function test_missing_issuer_zero_price_and_stale_revision_cannot_issue(): void
    {
        $data = $this->fixture();
        $data['items'][0]['unit_price'] = '0';
        $quote = app(QuoteService::class)->save($data, auth()->id());
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirm' => 1])->assertSessionHasErrors('items');
        CommercialSetting::query()->delete();
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 1, 'request_key' => (string) Str::uuid(), 'confirm' => 1])->assertSessionHasErrors('issuer');
        $this->post(route('admin.quotes.issue', $quote), ['revision' => 9, 'request_key' => (string) Str::uuid(), 'confirm' => 1])->assertStatus(409);
        $this->assertDatabaseCount('quote_versions', 0);
    }
}
