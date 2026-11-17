<?php

namespace Tests\Integration;

use App\Commerce\PublicCatalog;
use App\Commerce\QuoteService;
use App\Commerce\SupplierSync;
use App\Models\CommercialSetting;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\Support\CatalogDataset;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class MariaDbOutboxIntegrationTest extends TestCase
{
    use InteractsWithBaseAdmin;

    public function test_public_catalog_performance_and_cursor_continuity_on_large_isolated_datasets(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $report = [];
        foreach ([100, 1000, 5000] as $size) {
            DB::table('product_variants')->delete();
            DB::table('products')->delete();
            DB::table('product_categories')->delete();
            DB::transaction(fn () => CatalogDataset::create($size));
            $times = [];
            $sqlTimes = [];
            $sqlCounts = [];
            $sizes = [];
            $seen = [];
            $requests = 0;
            $url = '/catalogo/bloques';
            $memory = memory_get_usage(true);
            do {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                $response = $this->getJson($url)->assertOk();
                $times[] = (hrtime(true) - $start) / 1e6;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $sqlTimes[] = array_sum(array_column($queries, 'time'));
                $sqlCounts[] = count($queries);
                $sizes[] = strlen($response->getContent());
                preg_match_all('/data-product-id="([^"]+)"/', $response->json('html'), $matches);
                $seen = array_merge($seen, $matches[1]);
                $next = $response->json('next');
                $url = $next ? '/catalogo/bloques?'.parse_url($next, PHP_URL_QUERY) : null;
                $requests++;
                $this->assertLessThanOrEqual((int) ceil($size / 24), $requests);
            } while ($url);
            $this->assertCount($size, $seen);
            $this->assertCount($size, array_unique($seen));
            $this->assertLessThanOrEqual(4, max($sqlCounts));
            $this->assertLessThan(65000, max($sizes));
            $filterStart = hrtime(true);
            $filtered = $this->getJson('/catalogo/bloques?capacity=16&available=1&q=TEST')->assertOk();
            $filterMs = (hrtime(true) - $filterStart) / 1e6;
            $this->assertSame(24, $filtered->json('count'));
            sort($times);
            $report[] = ['products' => $size, 'variants' => $size * 4, 'requests_full_walk' => $requests,
                'query_count_max' => max($sqlCounts), 'query_ms_max' => round(max($sqlTimes), 2),
                'http_ms_p50' => round($times[(int) ceil(count($times) * .5) - 1], 2), 'http_ms_p95' => round($times[(int) ceil(count($times) * .95) - 1], 2), 'http_ms_max' => round(max($times), 2),
                'response_bytes_max' => max($sizes), 'filtered_block_ms' => round($filterMs, 2),
                'memory_growth_mib' => round((memory_get_usage(true) - $memory) / 1048576, 2), 'process_peak_mib' => round(memory_get_peak_usage(true) / 1048576, 2)];
        }
        $query = app(PublicCatalog::class)->query(['available' => 1, 'capacity' => 16])->orderByDesc('products.id')->limit(25);
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        File::ensureDirectoryExists(storage_path('app/private/qa/catalog'));
        file_put_contents(storage_path('app/private/qa/catalog/performance.json'), json_encode(['database' => 'usbstock_test', 'scenarios' => $report, 'explain' => $plan], JSON_PRETTY_PRINT));
    }

    public function test_daily_supplier_pipeline_is_unique_across_independent_workers(): void
    {
        $actor = $this->adminUser();
        $gate = tempnam(sys_get_temp_dir(), 'supplier-gate-');
        unlink($gate);
        $workers = collect([1, 2])->map(fn () => new Process([PHP_BINARY, base_path('tests/Support/mariadb_outbox_worker.php'), (string) $actor->id, $gate, 'pipeline'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']));
        foreach ($workers as $worker) {
            $worker->start();
        }
        touch($gate);
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        unlink($gate);
        $this->assertSame($workers[0]->getOutput(), $workers[1]->getOutput());
        $this->assertDatabaseCount('supplier_sync_runs', 1);
        $run = SupplierSyncRun::sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->download_attempts);
        $this->assertDatabaseCount('supplier_sync_notifications', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame(1, (int) DB::table('commerce_locks')->value('catalog_revision'));
        $this->assertNull(DB::table('commerce_locks')->value('sync_uuid'));
        Storage::build(['driver' => 'local', 'root' => storage_path('framework/testing/commerce-mariadb')])->delete($run->path);
    }

    public function test_commerce_sync_serializes_two_independent_workers(): void
    {
        config(['filesystems.disks.local.root' => storage_path('framework/testing/commerce-mariadb')]);
        $sync = app(SupplierSync::class);
        $first = $sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,100,,10.00\ntest1 -a,100,,10", 'fixture.csv', null);
        $second = $sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,200,,20.00\nTEST1-A,200,,20.00", 'fixture.csv', null);
        $gate = tempnam(sys_get_temp_dir(), 'commerce-gate-');
        unlink($gate);
        $workers = collect([$first, $second])->map(fn ($run) => new Process([PHP_BINARY, base_path('tests/Support/mariadb_outbox_worker.php'), (string) $run->id, $gate, 'commerce'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']));
        foreach ($workers as $worker) {
            $worker->start();
        } touch($gate);
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        unlink($gate);
        $this->assertSame(['applied', 'conflict'], $workers->map(fn ($w) => $w->getOutput())->sort()->values()->all());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertSame(1, (int) DB::table('commerce_locks')->value('catalog_revision'));
        $completed = SupplierSyncRun::where('status', 'completed')->sole();
        $this->assertSame(1, $completed->summary['duplicates_consolidated']);
        $this->assertSame(['TEST1'], $completed->summary['pending_content']);
        $this->assertSame([2, 3], $completed->summary['source_rows']['TEST1-A']);
        Storage::disk('local')->delete([$first->path, $second->path]);
    }

    public function test_csv_policy_preserves_json_provenance_retained_text_and_visibility_on_mariadb(): void
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $header = "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n";
        $sync->apply($sync->preview($header.'TEST1-A,100,Texto anterior,10', 'fixture.csv', null), null);
        $product = Product::sole();
        $product->update(['custom_description' => 'Personalización', 'is_published' => true]);
        $run = $sync->preview($header."test1 -a,90,,11\nTEST1-A,090,,11.00\nTEST2-A,0,,10", 'fixture.csv', null);
        $done = $sync->apply($run, null)->fresh();
        $this->assertSame(3, $done->summary['received_count']);
        $this->assertSame(2, $done->summary['unique_count']);
        $this->assertSame('retained_variant', $done->summary['description_sources']['TEST1-A']['source']);
        $this->assertSame(['TEST2'], $done->summary['pending_content']);
        $this->assertSame('Texto anterior', $product->fresh()->supplier_description);
        $this->assertSame('Personalización', $product->fresh()->description);
        $this->assertSame(1, Product::published()->count());
        Product::where('external_key', 'TEST2')->update(['is_published' => true, 'custom_description' => " \t\n"]);
        $this->assertSame(1, Product::published()->count());
        $same = $sync->apply($sync->preview($header."TEST1-A,90,,11\nTEST2-A,0,,10", 'fixture.csv', null), null);
        $this->assertSame(2, $same->summary['unchanged']);
        $this->assertSame(2, (int) DB::table('commerce_locks')->value('catalog_revision'));
    }

    public function test_commerce_json_totals_pdf_versions_and_foreign_keys_on_mariadb(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,100,Descripción de prueba,10.00", 'fixture.csv', $admin->id), $admin->id);
        CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'Emisor ficticio', 'issuer_contact' => 'prueba@example.test']]);
        $variant = ProductVariant::sole();
        $service = app(QuoteService::class);
        $quote = $service->save(['revision' => 1, 'customer_name' => 'Prueba', 'customer_email' => 'prueba@example.test', 'seller' => 'Vendedor ficticio', 'terms' => 'Condiciones ficticias', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '50.00', 'items' => [['variant_id' => $variant->id, 'quantity' => 50, 'unit_price' => '20.00', 'printing_price' => '2.00', 'discount' => '100.00']]], $admin->id);
        $key = (string) Str::uuid();
        $version = $service->issue($quote, 1, $key, $admin->id);
        $this->assertSame(122600, $version->fresh()->snapshot['totals']['total']);
        $this->assertSame($version->id, $service->issue($quote, 1, $key, $admin->id)->id);
        $this->assertSame(1, $quote->versions()->count());
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($version->path));
        try {
            $variant->delete();
            $this->fail('Debe conservarse la referencia');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private const CONNECTION = 'outbox_mariadb_test';

    private static bool $schemaCreated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('OUTBOX_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Set OUTBOX_MARIADB_TEST=1 to run against the isolated MariaDB test database.');
        }
        if (! getenv('OUTBOX_MARIADB_DATABASE') || ! getenv('OUTBOX_MARIADB_USERNAME') || ! getenv('OUTBOX_MARIADB_PASSWORD')) {
            throw new \RuntimeException('The dedicated MariaDB test database and runner credentials are required.');
        }

        $connection = [
            'driver' => 'mysql',
            'url' => null,
            'host' => getenv('OUTBOX_MARIADB_HOST') ?: '127.0.0.1',
            'port' => getenv('OUTBOX_MARIADB_PORT') ?: '3307',
            'database' => getenv('OUTBOX_MARIADB_DATABASE') ?: '',
            'username' => getenv('OUTBOX_MARIADB_USERNAME') ?: '',
            'password' => getenv('OUTBOX_MARIADB_PASSWORD') ?: '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ];
        $appDatabase = (string) config('database.connections.'.config('database.default').'.database');
        TestDatabaseSafety::assertConnection($connection, getenv('OUTBOX_MARIADB_TEST') ?: null, [$appDatabase]);
        config([
            'database.connections.'.self::CONNECTION => $connection,
            'database.default' => self::CONNECTION,
        ]);
        app('db')->purge(self::CONNECTION);
        if (! self::$schemaCreated) {
            Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]);
            self::$schemaCreated = true;
        } else {
            // Keep the validated schema, clear only the guarded disposable database.
            // DELETE avoids repeating dozens of DDL operations on an external disk.
            $this->assertSame('usbstock_test', DB::scalar('SELECT DATABASE()'));
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                DB::transaction(function (): void {
                    foreach (DB::getSchemaBuilder()->getTableListing(null, false) as $table) {
                        if ($table !== 'migrations') {
                            DB::table($table)->delete();
                        }
                    }
                    DB::table('commerce_locks')->insert(['id' => 1]);
                });
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
            $this->assertSame(25, DB::table('migrations')->count());
        }
    }

    public function test_mariadb_uses_persistent_atomic_state_to_prevent_repeated_delivery(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('mariadb@example.test', [
            'subject' => 'Prueba aislada',
            'message' => 'Prueba de estado MariaDB.',
        ]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(1, $message->attemptsHistory()->count());
    }

    public function test_mariadb_reclaims_an_expired_lease_without_reusing_its_attempt_record(): void
    {
        Mail::fake();
        $message = app(OutboxDispatcher::class)->email('mariadb@example.test', [
            'subject' => 'Prueba de lease',
            'message' => 'Recuperación de lease expirado.',
        ]);
        $token = (string) Str::uuid();
        $message->forceFill([
            'status' => OutboxMessage::PROCESSING,
            'attempts' => 1,
            'lease_token' => $token,
            'lease_expires_at' => now()->subSecond(),
        ])->save();
        $message->attemptsHistory()->create([
            'attempt_number' => 1,
            'lease_token' => $token,
            'started_at' => now()->subMinutes(6),
        ]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(2, $message->attemptsHistory()->count());
        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_message_id' => $message->id,
            'attempt_number' => 1,
            'result' => 'abandoned',
        ]);
    }

    public function test_json_column_semantics_and_nullable_idempotency_invariants(): void
    {
        $version = (string) DB::scalar('SELECT VERSION()');
        $payloadType = strtolower((string) DB::getSchemaBuilder()->getColumnType('outbox_messages', 'payload'));
        if (stripos($version, 'mariadb') !== false) {
            // MariaDB implements Laravel's JSON declaration as LONGTEXT plus JSON_VALID CHECK.
            $this->assertSame('longtext', $payloadType);
            $checks = DB::select("SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'outbox_messages' AND tc.CONSTRAINT_TYPE = 'CHECK'");
            $this->assertNotEmpty($checks);
            $clauses = implode(' ', array_map(fn (object $check): string => (string) $check->CHECK_CLAUSE, $checks));
            $this->assertStringContainsString('json_valid', strtolower($clauses));
            $this->assertStringContainsString('payload', strtolower($clauses));
        } else {
            // MySQL exposes JSON as a native column type and rejects invalid JSON values.
            $this->assertSame('json', $payloadType);
        }

        $indexes = collect(DB::select('SHOW INDEX FROM outbox_messages'))->pluck('Key_name')->all();
        $this->assertContains('outbox_messages_idempotency_key_unique', $indexes);
        $this->assertContains('outbox_ready_idx', $indexes);
        $this->assertContains('outbox_lease_idx', $indexes);

        $dispatcher = app(OutboxDispatcher::class);
        $first = $dispatcher->email('first@example.test', ['subject' => 'A', 'message' => 'A']);
        $second = $dispatcher->email('second@example.test', ['subject' => 'B', 'message' => 'B']);
        $this->assertSame(['subject' => 'A', 'message' => 'A'], $first->fresh()->payload);
        $this->assertNull($first->idempotency_key);
        $this->assertNull($second->idempotency_key);
        $this->assertNotSame($first->id, $second->id);

        try {
            DB::table('outbox_messages')->insert([
                'uuid' => (string) Str::uuid(),
                'category' => 'transactional',
                'channel' => 'email',
                'recipient' => 'invalid-json@example.test',
                'recipient_hash' => hash('sha256', 'invalid-json@example.test'),
                'template' => 'generic.email.v1',
                'payload' => '{invalid JSON',
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database accepted an invalid JSON payload.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_two_independent_processes_cannot_claim_the_same_mariadb_message(): void
    {
        $message = app(OutboxDispatcher::class)->email('race@example.test', ['subject' => 'Concurrente', 'message' => 'Reclamación concurrente.']);
        $gate = tempnam(sys_get_temp_dir(), 'outbox-gate-');
        if ($gate === false) {
            $this->fail('Could not create the local worker barrier.');
        }
        unlink($gate);
        $script = base_path('tests/Support/mariadb_outbox_worker.php');
        $workerEnvironment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'];
        $workers = [
            new Process([PHP_BINARY, $script, (string) $message->id, $gate], base_path(), $workerEnvironment),
            new Process([PHP_BINARY, $script, (string) $message->id, $gate], base_path(), $workerEnvironment),
        ];
        foreach ($workers as $worker) {
            $worker->start();
        }
        touch($gate);
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        unlink($gate);

        $this->assertSame(['claimed', 'skipped'], collect($workers)->map(fn (Process $worker) => $worker->getOutput())->sort()->values()->all());
        $this->assertSame(OutboxMessage::SENT, $message->fresh()->status);
        $this->assertSame(1, $message->attemptsHistory()->count());
    }
}
