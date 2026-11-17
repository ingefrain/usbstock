<?php

namespace Tests\Feature;

use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierNotifications;
use App\Commerce\SupplierPipeline;
use App\Commerce\SupplierSync;
use App\Commerce\SupplierTransferException;
use App\Models\OutboxMessage;
use App\Models\ProductVariant;
use App\Models\SupplierSyncNotification;
use App\Models\SupplierSyncRun;
use App\Services\Outbox\OutboxProcessor;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class SupplierOperationsTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private const CSV = "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nUSB1-A,100,Memoria ficticia,20.00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['queue.default' => 'sync']);
    }

    private function fakeDownload(string $csv = self::CSV): void
    {
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->andReturn($csv);
    }

    private function telegram(): void
    {
        config(['commerce.telegram_enabled' => true, 'commerce.environment_label' => 'PRUEBA', 'services.telegram.enabled' => true, 'services.telegram.bot_token' => 'test-token-not-secret', 'services.telegram.default_chat_id' => '123456789', 'services.telegram.allowed_chat_ids' => ['123456789'], 'notifications.transports.telegram.enabled' => true]);
    }

    public function test_start_precedes_download_and_failed_connection_persists_pending_notice(): void
    {
        $actor = $this->adminUser();
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->twice()->andReturnUsing(function () {
            $this->assertDatabaseHas('supplier_sync_runs', ['status' => 'running']);
            throw new SupplierTransferException('connection', 'connection_failed', true);
        });
        $run = app(SupplierPipeline::class)->fetch($actor->id, true);
        $this->assertSame('failed', $run->status);
        $this->assertSame('connection', $run->phase);
        $this->assertSame(2, $run->download_attempts);
        $this->assertNull($run->path);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('pending', SupplierSyncNotification::sole()->status);
        $this->assertDatabaseCount('product_variants', 0);
        Http::assertNothingSent();
    }

    public function test_daily_idempotency_dry_run_and_unchanged_catalog_revision(): void
    {
        $actor = $this->adminUser();
        $this->fakeDownload();
        $pipeline = app(SupplierPipeline::class);
        $dry = $pipeline->fetch($actor->id);
        $this->assertSame('ready', $dry->status);
        $this->assertDatabaseCount('products', 0);
        $run = $pipeline->fetch($actor->id, true, true);
        $again = $pipeline->fetch($actor->id, true, true);
        $this->assertSame($run->id, $again->id);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, DB::table('commerce_locks')->value('catalog_revision'));
        $unchanged = $pipeline->fetch($actor->id, true);
        $this->assertSame(1, $unchanged->summary['unchanged']);
        $this->assertSame(1, DB::table('commerce_locks')->value('catalog_revision'));
        $this->assertStringContainsString('Correcta sin cambios', app(SupplierNotifications::class)->text($unchanged));
        $this->assertSame($unchanged->id, app(SupplierSync::class)->apply($unchanged, $actor->id)->id);
    }

    public function test_anomaly_is_retained_and_blocks_later_automatic_runs(): void
    {
        $actor = $this->adminUser();
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview(self::CSV, 'test.csv', $actor->id), $actor->id);
        $this->fakeDownload(str_replace('100,Memoria', '0,Memoria', self::CSV));
        $review = app(SupplierPipeline::class)->fetch($actor->id, true);
        $this->assertSame('review', $review->status);
        $this->fakeDownload();
        $next = app(SupplierPipeline::class)->fetch($actor->id, true);
        $this->assertSame('review', $next->status);
        $this->assertSame('prior_review_pending', $next->error_code);
        $this->assertSame('review', $review->fresh()->status);
        $this->assertSame(100, ProductVariant::sole()->stock);
        $this->actingAsAdmin($actor)->post(route('admin.supplier-sync.dismiss', $review), ['reason' => 'Archivo incompleto confirmado en prueba.'])->assertRedirect();
        $this->assertSame('dismissed', $review->fresh()->status);
        Storage::disk('local')->assertExists($review->path);
    }

    public function test_invalid_downloads_never_apply_and_absences_are_soft(): void
    {
        $actor = $this->adminUser();
        foreach (['', 'WRONG,HEADER', self::CSV."\nUSB1-A,2,Duplicado,20", str_replace(',100,', ',-1,', self::CSV)] as $csv) {
            $this->fakeDownload($csv);
            $run = app(SupplierPipeline::class)->fetch($actor->id, true);
            $this->assertSame('invalid', $run->status);
            Storage::disk('local')->assertExists($run->path);
        }
        $this->assertDatabaseCount('products', 0);
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview(self::CSV."\nUSB2-A,100,Otra,20", 'fixture.csv', $actor->id), $actor->id);
        $missing = $sync->preview(self::CSV, 'missing.csv', $actor->id);
        $this->assertSame('review', $missing->status);
        $sync->apply($missing, $actor->id, true, true);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertFalse(ProductVariant::where('sku', 'USB2-A')->sole()->provider_active);
    }

    public function test_telegram_retries_reuse_outbox_and_preserve_success(): void
    {
        $this->telegram();
        $this->fakeDownload();
        $run = app(SupplierPipeline::class)->fetch($this->adminUser()->id, true);
        $notice = SupplierSyncNotification::sole();
        $this->assertSame($run->uuid, $notice->outbox->correlation_id);
        $this->assertSame('pending', $notice->delivery_status);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 1]], 429)->push(['ok' => true, 'result' => ['message_id' => 44, 'chat' => ['id' => 123456789]]])]);
        $processor = app(OutboxProcessor::class);
        $this->assertFalse($processor->process($notice->outbox_message_id));
        $this->travel(2)->seconds();
        $this->assertTrue($processor->process($notice->outbox_message_id));
        app(SupplierNotifications::class)->record($run);
        $this->assertFalse($processor->process($notice->outbox_message_id));
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('sent', $notice->fresh()->delivery_status);
        Http::assertSentCount(2);
        $this->travelBack();
    }

    public function test_transport_failure_and_disabled_configuration_do_not_rollback_catalog(): void
    {
        $this->telegram();
        config(['notifications.transports.telegram.max_attempts' => 1]);
        $this->fakeDownload();
        $run = app(SupplierPipeline::class)->fetch($this->adminUser()->id, true);
        Http::fake(['api.telegram.org/*' => Http::response([], 503)]);
        app(OutboxProcessor::class)->process(OutboxMessage::sole()->id);
        $this->assertSame('failed', OutboxMessage::sole()->status);
        $this->assertSame('completed', $run->fresh()->status);
        config(['services.telegram.allowed_chat_ids' => []]);
        $other = app(SupplierPipeline::class)->fetch($run->created_by, true);
        $this->assertSame('completed', $other->status);
        $this->assertSame('pending', $other->notifications()->sole()->status);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_messages_exclude_raw_errors_paths_costs_and_customer_data(): void
    {
        $this->fakeDownload();
        $run = app(SupplierPipeline::class)->fetch($this->adminUser()->id);
        $run->fill(['status' => 'failed', 'phase' => 'download', 'filename' => 'secret-client.csv', 'errors' => [['message' => 'secret-password-and-token']], 'error_code' => 'secret']);
        $text = app(SupplierNotifications::class)->text($run);
        foreach (['secret', '20.00', 'supplier-sync/', 'test-token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
        $this->assertStringContainsString('Cambios aplicados: no', $text);
        $this->assertStringContainsString('Fallo de descarga', $text);
    }

    public function test_lease_blocks_concurrent_execution_and_expired_lease_is_recovered(): void
    {
        $actor = $this->adminUser();
        $this->fakeDownload();
        DB::table('commerce_locks')->update(['sync_uuid' => 'active-test', 'sync_expires_at' => now()->addMinutes(5)]);
        try {
            app(SupplierPipeline::class)->fetch($actor->id);
            $this->fail('An active download must prevent another download.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('supplier_sync_runs', 0);
        DB::table('commerce_locks')->update(['sync_expires_at' => now()->subMinute()]);
        $this->assertSame('ready', app(SupplierPipeline::class)->fetch($actor->id)->status);
        $this->assertNull(DB::table('commerce_locks')->value('sync_uuid'));
    }

    public function test_schedule_is_midnight_mexico_even_with_another_application_timezone(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'supplier:sync'));
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertSame('America/Mexico_City', $event->timezone);
        $this->assertFalse($event->filtersPass($this->app));
        config(['commerce.sync.enabled' => true, 'app.timezone' => 'Asia/Tokyo']);
        foreach (['2026-01-17 06:00:00' => true, '2026-07-17 06:00:00' => true, '2026-11-17 00:00:00' => false, '2026-11-17 06:01:00' => false] as $utc => $due) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->assertSame($due, $event->isDue($this->app));
        }
        Carbon::setTestNow();
    }

    public function test_remote_command_requires_authorized_actor_and_dry_run_overrides_apply(): void
    {
        $this->artisan('supplier:sync --fetch --dry-run')->assertFailed();
        $this->artisan('supplier:sync --fetch --scheduled')->assertFailed();
        $this->fakeDownload();
        $this->artisan('supplier:sync', ['--fetch' => true, '--apply' => true, '--dry-run' => true, '--actor' => $this->adminUser()->id])->assertSuccessful();
        $this->assertDatabaseCount('products', 0);
        $this->assertSame('ready', SupplierSyncRun::sole()->status);
    }

    public function test_application_exception_rolls_back_every_product_and_retains_evidence(): void
    {
        $this->fakeDownload();
        ProductVariant::creating(fn () => throw new \RuntimeException('private database detail'));
        try {
            $run = app(SupplierPipeline::class)->fetch($this->adminUser()->id, true);
            $this->assertSame('failed', $run->status);
            $this->assertSame('apply', $run->phase);
            $this->assertNull($run->applied_at);
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('product_variants', 0);
            $this->assertSame(0, DB::table('commerce_locks')->value('catalog_revision'));
            Storage::disk('local')->assertExists($run->path);
            $this->assertStringNotContainsString('private database', $run->toJson());
            $this->assertDatabaseCount('supplier_sync_notifications', 1);
        } finally {
            ProductVariant::flushEventListeners();
        }
    }

    public function test_abandoned_telegram_delivery_is_uncertain_and_never_resent(): void
    {
        $this->telegram();
        $this->fakeDownload();
        app(SupplierPipeline::class)->fetch($this->adminUser()->id);
        $message = OutboxMessage::sole();
        $message->update(['status' => 'processing', 'attempts' => 1, 'lease_expires_at' => now()->subMinute()]);
        $this->assertFalse(app(OutboxProcessor::class)->process($message->id));
        $this->assertSame('uncertain', $message->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_expired_daily_execution_is_closed_without_redownloading(): void
    {
        $actor = $this->adminUser();
        $run = SupplierSyncRun::create(['created_by' => $actor->id, 'source' => 'ftp', 'filename' => 'provider.csv', 'status' => 'running', 'phase' => 'connection', 'daily_key' => now('America/Mexico_City')->format('Y-m-d'), 'started_at' => now()->subMinutes(20), 'summary' => [], 'errors' => []]);
        DB::table('commerce_locks')->update(['sync_uuid' => $run->uuid, 'sync_expires_at' => now()->subMinutes(10)]);
        $this->mock(SupplierDownloader::class)->shouldNotReceive('download');
        $recovered = app(SupplierPipeline::class)->fetch($actor->id, true, true);
        $this->assertSame($run->id, $recovered->id);
        $this->assertSame('failed', $recovered->status);
        $this->assertSame('interrupted', $recovered->error_code);
        $this->assertDatabaseCount('supplier_sync_notifications', 1);
        $this->assertNull(DB::table('commerce_locks')->value('sync_uuid'));
    }

    public function test_download_and_processing_failures_are_distinct_and_bounded(): void
    {
        $actor = $this->adminUser();
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->once()->andThrow(new SupplierTransferException('download', 'download_failed'));
        $failed = app(SupplierPipeline::class)->fetch($actor->id, true);
        $this->assertSame('download', $failed->phase);
        $this->assertSame(1, $failed->download_attempts);
        $this->assertStringContainsString('Fallo de descarga', $failed->notifications()->sole()->text);
        $this->fakeDownload();
        Storage::shouldReceive('disk')->with('local')->andReturn(new class
        {
            public function put(): never
            {
                throw new \RuntimeException('private storage detail');
            }
        });
        $processing = app(SupplierPipeline::class)->fetch($actor->id, true);
        $this->assertSame('failed', $processing->status);
        $this->assertSame('processing', $processing->phase);
        $this->assertStringContainsString('Fallo de procesamiento', $processing->notifications()->sole()->text);
        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }
}
