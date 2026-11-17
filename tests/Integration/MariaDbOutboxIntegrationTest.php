<?php

namespace Tests\Integration;

use App\Commerce\QuoteService;
use App\Commerce\SupplierSync;
use App\Models\CommercialSetting;
use App\Models\OutboxMessage;
use App\Models\ProductVariant;
use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class MariaDbOutboxIntegrationTest extends TestCase
{
    use InteractsWithBaseAdmin;

    public function test_commerce_sync_serializes_two_independent_workers(): void
    {
        config(['filesystems.disks.local.root' => storage_path('framework/testing/commerce-mariadb')]);
        $sync = app(SupplierSync::class);
        $first = $sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,100,Prueba aislada,10.00", 'fixture.csv', null);
        $second = $sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,200,Prueba aislada,20.00", 'fixture.csv', null);
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
        Storage::disk('local')->delete([$first->path, $second->path]);
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
            $this->assertSame(23, DB::table('migrations')->count());
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
