<?php

namespace Tests\Feature;

use App\Commerce\SupplierCatalogFingerprint;
use App\Commerce\SupplierCsvComparison;
use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierPipeline;
use App\Commerce\SupplierReadOnly;
use App\Commerce\SupplierSync;
use App\Commerce\SupplierTrial;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class SupplierTrialTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private const HEADER = "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\n";

    private const CSV = self::HEADER.'TEST-A,100,Descripción privada de prueba,123.45';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function configuration(): string
    {
        Storage::disk('local')->put('supplier-trial/connection.env', "SUPPLIER_TRIAL_DATABASE=usbstock_dev\nSUPPLIER_FTP_HOST=supplier.example.test\nSUPPLIER_FTP_USER=synthetic-reader\nSUPPLIER_FTP_PASSWORD=synthetic-secret\nSUPPLIER_FTP_PROTOCOL=ftp\nSUPPLIER_FTP_PORT=21\nSUPPLIER_FTP_PATH=/FTP/Existencias-ftp-precio.csv\nSUPPLIER_TRIAL_ALLOW_PLAINTEXT=true\n");
        chmod(Storage::disk('local')->path('supplier-trial/connection.env'), 0600);
        Storage::disk('local')->put('baseline.csv', self::CSV);

        return Storage::disk('local')->path('baseline.csv');
    }

    public function test_preflight_is_offline_and_does_not_create_a_run_even_with_complete_configuration(): void
    {
        $actor = $this->adminUser();
        $baseline = $this->configuration();
        $this->mock(SupplierDownloader::class)->shouldNotReceive('download');
        $this->artisan('supplier:trial', ['--actor' => $actor->id, '--baseline' => $baseline])->assertSuccessful();
        $this->assertDatabaseCount('supplier_sync_runs', 0);
        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }

    public function test_download_requires_actor_private_settings_disabled_automation_and_plaintext_acceptance(): void
    {
        $actor = $this->adminUser();
        $baseline = $this->configuration();
        $trial = app(SupplierTrial::class);
        $this->mock(SupplierDownloader::class)->shouldNotReceive('download');
        config(['commerce.sync.auto_apply' => true]);
        $this->assertContains('must_be_disabled:commerce.sync.auto_apply', $trial->preflight($actor, $baseline)['issues']);
        $this->artisan('supplier:trial', ['--actor' => $actor->id, '--baseline' => $baseline, '--download' => true])->assertFailed();
        config(['commerce.sync.auto_apply' => false]);
        Storage::disk('local')->put('supplier-trial/connection.env', str_replace('ALLOW_PLAINTEXT=true', 'ALLOW_PLAINTEXT=false', Storage::disk('local')->get('supplier-trial/connection.env')));
        $this->assertContains('plaintext_requires_explicit_acceptance', $trial->preflight($actor, $baseline)['issues']);
        $this->assertContains('actor_not_authorized', $trial->preflight($this->userWithRole('operator'), $baseline)['issues']);
        Storage::disk('local')->put('supplier-trial/connection.env', 'SUPPLIER_FTP_PASSWORD="sensitive broken value');
        Artisan::call('supplier:trial', ['--actor' => $actor->id, '--baseline' => $baseline, '--download' => true]);
        $this->assertStringNotContainsString('sensitive broken', Artisan::output());
        $this->assertDatabaseCount('supplier_sync_runs', 0);
    }

    public function test_trial_preserves_catalog_and_reports_comparison_without_costs_or_notices(): void
    {
        $actor = $this->adminUser();
        $baseline = $this->configuration();
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview(self::CSV, 'fixture.csv', $actor->id), $actor->id);
        $before = app(SupplierCatalogFingerprint::class)->capture();
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->once()->andReturn(str_replace(',100,', ',90,', self::CSV));
        $report = app(SupplierTrial::class)->download($actor, $baseline);
        $this->assertTrue($report['catalog_unchanged']);
        $this->assertFalse($report['applied']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame(1, $report['comparison']['changes']['counts']['stock_changed_skus']);
        $this->assertSame($before, app(SupplierCatalogFingerprint::class)->capture());
        $this->assertSame(100, ProductVariant::sole()->stock);
        $this->assertFalse(config('commerce.sync.ftp_enabled'));
        $this->assertFalse((bool) config('commerce.sync.read_only'));
        $this->assertSame(2, config('commerce.sync.download_attempts'));
        $json = Storage::disk('local')->get($report['report_path']);
        foreach (['123.45', '12345', 'synthetic-secret', 'Descripción privada'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        Storage::disk('local')->assertExists($report['csv_path']);
        $this->assertDatabaseCount('supplier_sync_notifications', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        Http::assertNothingSent();
    }

    public function test_dry_run_overrides_apply_all_flags_and_notifications_and_restores_configuration(): void
    {
        $actor = $this->adminUser();
        config(['commerce.sync.auto_apply' => true, 'commerce.sync.enabled' => true, 'commerce.sync.deactivate_missing' => true,
            'commerce.telegram_enabled' => true, 'services.telegram.enabled' => true, 'commerce.notify_email' => 'test@example.test']);
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->andReturn(self::CSV);
        $this->artisan('supplier:sync', ['--fetch' => true, '--dry-run' => true, '--apply' => true, '--actor' => $actor->id])->assertSuccessful();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('supplier_sync_notifications', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertTrue(config('commerce.sync.auto_apply'));
        $this->assertTrue(config('commerce.telegram_enabled'));
        app(SupplierReadOnly::class)->run(function () use ($actor): void {
            $run = app(SupplierPipeline::class)->fetch($actor->id, true, true);
            $this->assertSame('ready', $run->status);
            $this->assertNull($run->daily_key);
            try {
                app(SupplierSync::class)->apply($run, $actor->id, true, true);
                $this->fail('Apply must fail inside the read-only scope.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        });
        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }

    public function test_comparison_ignores_order_and_numeric_format_but_reports_every_commercial_difference(): void
    {
        $comparison = app(SupplierCsvComparison::class);
        $old = self::HEADER."TEST-A,100,Texto,10\nTEST-B,200,Texto,10";
        $reordered = self::HEADER."test-b,0200,Texto,10.00\nTEST-A,100,Texto,10.0\nTEST-A,100,Texto,10";
        $same = $comparison->compare($old, $reordered);
        $this->assertTrue($same['changes']['same_catalog']);
        $this->assertNotSame($same['baseline']['sha256'], $same['current']['sha256']);
        $this->assertSame(1, $same['current']['duplicates_consolidated']);
        $changed = $comparison->compare($old, self::HEADER."TEST-A,0,,30\nNEW-A,0,Nuevo,10");
        $this->assertSame(['NEW-A'], $changed['changes']['skus']['new_skus']);
        $this->assertSame(['TEST-B'], $changed['changes']['skus']['missing_skus']);
        $this->assertSame(['TEST-A'], $changed['changes']['skus']['stock_changed_skus']);
        $this->assertSame(['TEST-A'], $changed['changes']['skus']['cost_changed_skus']);
        $this->assertSame(['TEST-A'], $changed['changes']['skus']['description_changed_skus']);
        $this->assertSame(-300, $changed['changes']['total_stock_delta']);
        $this->assertTrue($changed['changes']['suspicious']);
        $this->assertTrue($changed['changes']['stock_drop_alert']);
        $this->assertTrue($changed['changes']['missing_alert']);
        $this->assertSame(1, $changed['current']['empty_descriptions']);
        $invalid = $comparison->compare($old, self::HEADER."TEST-A,100,Texto,10\nTEST-A,101,Texto,10");
        $this->assertFalse($invalid['comparable']);
        $this->assertNull($invalid['changes']);
        $this->assertFalse($comparison->compare('', $old)['comparable']);
    }

    public function test_comparison_command_is_database_independent_and_keeps_cost_values_private(): void
    {
        $baseline = $this->configuration();
        $this->artisan('supplier:compare', ['baseline' => $baseline, 'current' => $baseline])->assertSuccessful();
        $reports = Storage::disk('local')->files('supplier-comparisons');
        $this->assertCount(1, $reports);
        $report = json_decode(Storage::disk('local')->get($reports[0]), true);
        $this->assertTrue($report['changes']['same_catalog']);
        $this->assertDatabaseCount('supplier_sync_runs', 0);
        $this->assertDatabaseCount('products', 0);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_transfer_failures_and_unexpected_data_never_change_existing_catalog(): void
    {
        require base_path('tests/Support/supplier_curl_double.php');
        $actor = $this->adminUser();
        $baseline = $this->configuration();
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview(self::CSV, 'fixture.csv', $actor->id), $actor->id);
        $before = app(SupplierCatalogFingerprint::class)->capture();
        $cases = [
            'correct' => [[], 'ready', null],
            'interrupted' => [['failure_on' => 2, 'errno' => CURLE_PARTIAL_FILE], 'failed', 'partial_file'],
            'timeout' => [['failure_on' => 2, 'errno' => CURLE_OPERATION_TIMEDOUT], 'failed', 'timeout'],
            'authentication' => [['failure_on' => 1, 'errno' => 67], 'failed', 'authentication_failed'],
            'certificate' => [['failure_on' => 1, 'errno' => 60], 'failed', 'certificate_failed'],
            'changed_timestamp' => [['modified_after' => 1700000001], 'failed', 'remote_changed'],
            'changed_size' => [['size_after' => 123456], 'failed', 'remote_changed'],
            'unknown_metadata' => [['modified_before' => -1], 'failed', 'metadata_unavailable'],
            'too_large' => [['size_before' => 8388609], 'failed', 'too_large'],
            'stream_too_large' => [['size_before' => 1, 'bytes' => str_repeat('x', 8388609)], 'failed', 'too_large'],
            'length_mismatch' => [['size_before' => 999], 'failed', 'remote_changed'],
            'empty' => [['bytes' => ''], 'invalid', null],
            'corrupt' => [['bytes' => self::HEADER.'TEST-A,100,"Truncated,10'], 'invalid', null],
            'unexpected' => [['bytes' => self::HEADER.'TEST-A,-1,Texto,10'], 'invalid', null],
        ];
        foreach ($cases as $name => [$scenario, $status, $error]) {
            $GLOBALS['supplier_curl_history'] = [];
            $GLOBALS['supplier_curl_scenario'] = $scenario + ['bytes' => self::CSV];
            $report = app(SupplierTrial::class)->download($actor, $baseline);
            $this->assertSame($status, $report['status'], $name);
            $this->assertSame($error, $report['error_code'], $name);
            $this->assertTrue($report['catalog_unchanged'], $name);
            $this->assertSame($before, app(SupplierCatalogFingerprint::class)->capture(), $name);
            $this->assertFalse(config('commerce.sync.ftp_enabled'));
            foreach ($GLOBALS['supplier_curl_history'] as $request) {
                $this->assertTrue($request->closed, $name);
                $this->assertFalse($request->options[CURLOPT_UPLOAD]);
                $this->assertFalse($request->options[CURLOPT_TRANSFERTEXT]);
                $this->assertTrue($request->options[CURLOPT_FTP_USE_EPSV]);
                $this->assertTrue($request->options[CURLOPT_FTP_SKIP_PASV_IP]);
                $this->assertFalse($request->options[CURLOPT_VERBOSE]);
                $this->assertSame('', $request->options[CURLOPT_PROXY]);
            }
            $run = SupplierSyncRun::where('uuid', $report['run_uuid'])->sole();
            $this->assertSame(1, $run->download_attempts);
            $this->assertNull($run->applied_at);
        }
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertDatabaseCount('supplier_sync_notifications', 0);
        Http::assertNothingSent();
    }
}
