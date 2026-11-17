<?php

namespace Tests\Feature;

use App\Commerce\PublicCatalog;
use App\Commerce\SupplierCsv;
use App\Commerce\SupplierNotifications;
use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class SupplierCsvPolicyTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private const HEADER = "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function preview(string $rows): SupplierSyncRun
    {
        return app(SupplierSync::class)->preview(self::HEADER.$rows, 'fixture.csv', null);
    }

    private function apply(SupplierSyncRun $run, bool $missing = false, bool $approve = false): SupplierSyncRun
    {
        return app(SupplierSync::class)->apply($run, null, $missing, $approve);
    }

    private function blocked(SupplierSyncRun $run): void
    {
        try {
            $this->apply($run);
            $this->fail('An invalid or unapproved run must never apply.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_identical_and_normalized_duplicates_preserve_all_rows_and_count_once(): void
    {
        $bytes = "usb1 -a,000100,Texto exacto,10.0\nUSB1-A,100,Texto exacto,10.00\nUSB1-A,100,Texto exacto,10";
        $run = $this->preview($bytes);
        $this->assertSame('ready', $run->status);
        $this->assertSame(3, $run->summary['received_count']);
        $this->assertSame(1, $run->summary['unique_count']);
        $this->assertSame(2, $run->summary['duplicates_consolidated']);
        $this->assertSame([2, 3, 4], $run->summary['source_rows']['USB1-A']);
        $this->assertSame(self::HEADER.$bytes, Storage::disk('local')->get($run->path));
        $applied = $this->apply($run);
        $this->assertSame(1, $applied->summary['new']);
        $this->assertDatabaseCount('product_variants', 1);
        $this->apply($applied);
        $next = $this->apply($this->preview($bytes));
        $this->assertSame(1, $next->summary['unchanged']);
        $this->assertSame(0, $next->summary['changed']);
        $this->assertSame(1, DB::table('commerce_locks')->value('catalog_revision'));
    }

    public static function conflicts(): array
    {
        return [['USB1-A,101,Texto,10'], ['usb1 -a,100,Texto,11'], ['USB1-A,100, Texto,10'], ['USB1-A,100,,10']];
    }

    #[DataProvider('conflicts')]
    public function test_conflicting_duplicates_reject_the_entire_file(string $second): void
    {
        $run = $this->preview("USB1-A,100,Texto,10\n".$second);
        $this->assertSame('invalid', $run->status);
        $this->assertSame([2, 3], $run->errors[0]['rows']);
        $this->blocked($run);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame(0, DB::table('commerce_locks')->value('catalog_revision'));
    }

    public function test_blank_existing_descriptions_retain_variant_and_model_provenance_and_custom_text(): void
    {
        $this->apply($this->preview('USB1-A,100,Texto anterior,10'));
        $product = Product::sole();
        $product->update(['custom_description' => 'Personalización aprobada', 'is_published' => true]);
        $run = $this->preview("USB1-A,90,,11\nUSB1-B,50,   ,11");
        $this->assertSame('retained_variant', $run->summary['description_sources']['USB1-A']['source']);
        $this->assertSame('retained_model', $run->summary['description_sources']['USB1-B']['source']);
        $this->assertSame(2, $run->summary['descriptions_retained']);
        $this->assertSame([], $run->summary['pending_content']);
        $this->apply($run);
        $this->assertSame('Texto anterior', $product->fresh()->supplier_description);
        $this->assertSame('Personalización aprobada', $product->fresh()->description);
        $this->assertTrue($product->fresh()->isPublic());
        $this->assertSame(90, ProductVariant::where('sku', 'USB1-A')->sole()->stock);
        $this->assertSame(1100, ProductVariant::where('sku', 'USB1-A')->sole()->supplier_price_minor);
        $same = $this->apply($this->preview("USB1-A,90,,11\nUSB1-B,50,,11"));
        $this->assertSame(2, $same->summary['unchanged']);
        $this->assertSame(2, DB::table('commerce_locks')->value('catalog_revision'));
    }

    public function test_blank_new_model_requires_explicit_authorized_publication_with_valid_content(): void
    {
        $this->actingAsAdmin();
        $run = $this->apply($this->preview('USB1-A,100,,10'));
        $product = Product::sole();
        $this->assertSame(['USB1'], $run->summary['pending_content']);
        $this->assertTrue($product->content_review_required);
        $this->assertFalse($product->is_published);
        $this->assertSame('', $product->supplier_description);
        $this->put(route('admin.products.update', $product), ['is_published' => 1, 'custom_description' => " \t\n"])->assertSessionHasErrors('is_published');
        $this->assertFalse($product->fresh()->is_published);
        $this->put(route('admin.products.update', $product), ['is_published' => 1, 'custom_description' => 'Descripción revisada'])->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->isPublic());
        $this->get('/producto/'.$product->slug)->assertOk();
        $this->apply($this->preview('USB1-A,95,,11'));
        $this->assertSame('Descripción revisada', $product->fresh()->description);
        $this->assertSame('', $product->fresh()->supplier_description);
        $this->actingAsAdmin($this->userWithPermissions(['products.view']))
            ->put(route('admin.products.update', $product), ['is_published' => 1, 'custom_description' => 'Otro texto'])->assertForbidden();
    }

    public function test_public_scopes_reject_blank_text_even_if_publication_flag_was_set_elsewhere(): void
    {
        $this->apply($this->preview('USB1-A,100,,10'));
        $product = Product::sole();
        $product->update(['is_published' => true, 'supplier_description' => " \t\n\r", 'custom_description' => '  ']);
        $this->assertFalse($product->fresh()->isPublic());
        $this->assertSame(0, Product::published()->count());
        $this->get('/producto/'.$product->slug)->assertNotFound();
        $this->getJson('/catalogo/bloques')->assertJsonPath('count', 0);
        $this->get('/sitemap.xml')->assertDontSee('/producto/'.$product->slug);
    }

    public function test_model_uses_current_nonblank_variant_without_inventing_text_for_blank_new_variant(): void
    {
        $this->apply($this->preview("USB1-A,100,,10\nUSB1-B,100,Texto válido,10"));
        $product = Product::sole();
        $this->assertSame('Texto válido', $product->supplier_description);
        $this->assertSame('', ProductVariant::where('sku', 'USB1-A')->sole()->supplier_description);
        $this->assertFalse($product->is_published);
        $this->apply($this->preview("USB1-A,100,,10\nUSB1-B,100,Texto nuevo,10"));
        $this->assertSame('Texto nuevo', $product->fresh()->supplier_description);
        $this->assertFalse($product->fresh()->is_published);
    }

    public function test_zero_stock_is_present_and_missing_variants_require_explicit_policy(): void
    {
        $this->apply($this->preview("USB1-A,100,Uno,10\nUSB2-A,100,Dos,10"));
        $run = $this->preview('USB1-A,0,,10');
        $this->assertSame(1, $run->summary['missing']);
        $this->assertTrue($run->summary['stock_drop']);
        $this->blocked($run);
        $this->apply($run, false, true);
        $this->assertTrue(ProductVariant::where('sku', 'USB2-A')->sole()->provider_active);
        $next = $this->apply($this->preview('USB1-A,0,,10'), true, true);
        $this->assertSame(1, $next->summary['deactivated']);
        $this->assertTrue(ProductVariant::where('sku', 'USB1-A')->sole()->provider_active);
        $this->assertSame(0, ProductVariant::where('sku', 'USB1-A')->sole()->stock);
        $this->assertFalse(ProductVariant::where('sku', 'USB2-A')->sole()->provider_active);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public static function invalidFiles(): array
    {
        return [
            [''], [self::HEADER], ['WRONG,HEADER'], [self::HEADER."USB1-A,100,\xff,10"],
            [self::HEADER."USB1-A,100,,10\nUSB2-A,100,Valid,1.001"],
            [self::HEADER."USB1-A,100,,10\nUSB2-A,100,\"truncated,10"],
            [self::HEADER."USB1-A,100,,10\nUSB2-A,-1,Valid,10"],
            [self::HEADER."USB1-A,100,,10\nUSB2-A,100000000,Valid,10"],
            [self::HEADER.'USB1-A,100,Valid,1000000000'],
            [self::HEADER.'BAD,100,Valid,10'], [self::HEADER.'USB1-A,100,'.str_repeat('x', 4001).',10'],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_fatal_files_never_mutate_existing_inventory_even_when_warnings_exist(string $csv): void
    {
        $this->apply($this->preview('USB1-A,100,Original,10'));
        $before = ProductVariant::sole()->getAttributes();
        $run = app(SupplierSync::class)->preview($csv, 'invalid.csv', null);
        $this->assertSame('invalid', $run->status);
        $this->blocked($run);
        $this->assertSame($before, ProductVariant::sole()->getAttributes());
        $this->assertSame(1, DB::table('commerce_locks')->value('catalog_revision'));
    }

    public function test_warnings_cannot_bypass_economic_review_or_server_permissions(): void
    {
        $this->actingAsAdmin();
        $this->apply($this->preview('USB1-A,100,Original,10'));
        $run = $this->preview("USB1-A,100,,30\nUSB1-A,100,,30");
        $this->assertSame('review', $run->status);
        $this->assertSame(1, $run->summary['price_alerts']);
        $this->assertSame(1, $run->summary['changed']);
        $this->blocked($run);
        $this->actingAsAdmin($this->userWithPermissions(['supplier_sync.view', 'supplier_sync.apply']))
            ->post(route('admin.supplier-sync.apply', $run), ['confirm' => 1, 'approve_large_changes' => 1])->assertForbidden();
        $this->assertSame(1000, ProductVariant::sole()->supplier_price_minor);
        $this->actingAsAdmin()->post(route('admin.supplier-sync.apply', $run), ['confirm' => 1, 'approve_large_changes' => 1])->assertRedirect();
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_transaction_failure_rolls_back_updates_new_models_and_revision(): void
    {
        $this->apply($this->preview('USB1-A,100,Original,10'));
        $run = $this->preview("USB1-A,90,,11\nUSB2-A,100,,10");
        ProductVariant::creating(fn () => throw new \RuntimeException('Synthetic rollback'));
        try {
            $this->apply($run);
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic rollback', $error->getMessage());
        } finally {
            ProductVariant::flushEventListeners();
        }
        $this->assertSame(100, ProductVariant::sole()->stock);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame(1, DB::table('commerce_locks')->value('catalog_revision'));
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertNull($run->fresh()->applied_at);
        $this->assertNotEmpty($run->fresh()->summary['warnings']);
    }

    public function test_thousands_of_variants_and_duplicates_are_bounded_and_continuous_catalog_remains_consistent(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1500; $i++) {
            $rows[] = 'TEST'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'-A,100,Modelo ficticio,10';
            $rows[] = 'TEST'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'-B,0,,10';
        }
        $rows[] = $rows[0];
        $run = $this->apply($this->preview(implode("\n", $rows)));
        $this->assertSame(3001, $run->summary['received_count']);
        $this->assertSame(3000, $run->row_count);
        $this->assertSame(1500, $run->summary['new_models']);
        $this->assertDatabaseCount('product_variants', 3000);
        $this->assertSame(0, Product::published()->count());
        Product::query()->update(['is_published' => true]); // Explicit isolated fixture approval.
        $catalog = app(PublicCatalog::class);
        $first = $catalog->block([]);
        $second = $catalog->block([], $first['next']);
        $this->assertCount(24, $first['products']);
        $this->assertCount(0, $first['products']->pluck('id')->intersect($second['products']->pluck('id')));
        $rows[0] = str_replace(',100,', ',99,', $rows[0]);
        array_pop($rows);
        $this->apply($this->preview(implode("\n", $rows)));
        $this->expectException(HttpException::class);
        $catalog->block([], $first['next']);
    }

    public function test_limits_and_multiline_record_references_remain_enforced(): void
    {
        $invalid = $this->preview(str_repeat("USB1-A,-1,Texto,10\n", 105));
        $this->assertSame('invalid', $invalid->status);
        $this->assertSame(105, $invalid->summary['error_count']);
        $this->assertCount(100, $invalid->errors);
        $this->assertStringContainsString('Errores: 105', app(SupplierNotifications::class)->text($invalid));
        $parsed = app(SupplierCsv::class)->parse(self::HEADER."USB1-A,1,\"Dos\nlíneas\",10\nUSB1-A,1,\"Dos\nlíneas\",10");
        $this->assertSame([2, 3], $parsed['source_rows']['USB1-A']);
        config(['commerce.sync.max_rows' => 2]);
        $run = $this->preview("USB1-A,1,,10\nUSB1-B,1,,10\nUSB1-C,1,,10");
        $this->assertSame('invalid', $run->status);
        config(['commerce.sync.max_bytes' => 64]);
        $this->expectException(ValidationException::class);
        app(SupplierCsv::class)->parse(str_repeat('x', 65));
    }

    public function test_twenty_thousand_identical_rows_keep_linear_provenance_and_one_warning(): void
    {
        $parsed = app(SupplierCsv::class)->parse(self::HEADER.str_repeat("USB1-A,100,Texto,10\n", 20000));
        $this->assertSame(20000, $parsed['received_count']);
        $this->assertSame(19999, $parsed['duplicates_consolidated']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertCount(1, $parsed['warnings']);
        $this->assertCount(20000, $parsed['warnings'][0]['rows']);
        $this->assertSame(20001, $parsed['source_rows']['USB1-A'][19999]);
    }

    public function test_admin_views_and_notification_distinguish_warnings_conflicts_and_anomalies(): void
    {
        $this->actingAsAdmin();
        $run = $this->preview("USB1-A,100,,10\nUSB1-A,100,,10");
        $html = $this->get(route('admin.supplier-sync.show', $run))->assertOk()
            ->assertSee('Archivo válido con advertencias')->assertSee('Pendientes de revisión de contenido')->getContent();
        File::ensureDirectoryExists(storage_path('app/private/qa/csv-policy'));
        file_put_contents(storage_path('app/private/qa/csv-policy/warnings.html'), $html);
        $this->get(route('admin.supplier-sync.index'))->assertSee('Recibidos / únicos');
        $invalid = $this->preview("USB2-A,100,Texto,10\nUSB2-A,101,Texto,10");
        file_put_contents(storage_path('app/private/qa/csv-policy/invalid.html'), $this->get(route('admin.supplier-sync.show', $invalid))->assertOk()->assertSee('Registros 2, 3')->getContent());
        $done = $this->apply($run);
        file_put_contents(storage_path('app/private/qa/csv-policy/product.html'), $this->get(route('admin.products.edit', Product::sole()))->assertOk()->getContent());
        $review = $this->preview('USB1-A,0,,30');
        file_put_contents(storage_path('app/private/qa/csv-policy/review.html'), $this->get(route('admin.supplier-sync.show', $review))->assertOk()->assertSee('Pendiente de aprobación por anomalías')->getContent());
        $notifications = app(SupplierNotifications::class);
        $text = $notifications->text($done);
        foreach (['con advertencias', 'Recibidos: 2', 'Únicos: 1', 'Duplicados consolidados: 1', 'Descripciones vacías: 2'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('Rechazada por validaciones', $notifications->text($invalid));
        $this->assertStringContainsString('Anomalías pendientes: sí', $notifications->text($review));
        $notifications->record($done);
        Http::assertNothingSent();
        $this->assertDatabaseCount('outbox_messages', 0);
    }
}
