<?php

use App\Commerce\CommercialPricing;
use App\Commerce\QuoteService;
use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierPipeline;
use App\Commerce\SupplierSync;
use App\Models\CommercialPriceScale;
use App\Models\Quote;
use App\Models\SupplierSyncRun;
use App\Services\Outbox\OutboxProcessor;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
TestDatabaseSafety::assertConnection($connection, getenv('OUTBOX_MARIADB_TEST') ?: null);
config(['database.connections.outbox_mariadb_test' => $connection, 'database.default' => 'outbox_mariadb_test', 'mail.default' => 'log']);
app('db')->purge('outbox_mariadb_test');

$messageId = (int) ($argv[1] ?? 0);
$gate = (string) ($argv[2] ?? '');
$deadline = microtime(true) + 20;
while (! is_file($gate)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}

if (($argv[3] ?? '') === 'quote-pricing') {
    $quote = Quote::findOrFail($messageId);
    $line = $quote->items->sole();
    $proposal = app(CommercialPricing::class)->proposal($line->variant, $line->quantity);
    try {
        app(QuoteService::class)->save(['revision' => 1, 'customer_name' => 'Concurrent QA', 'customer_email' => 'qa@example.test', 'terms' => 'Isolated QA', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '0',
            'items' => [['variant_id' => $line->product_variant_id, 'quantity' => $line->quantity, 'unit_price' => $proposal['price'], 'printing_price' => '0', 'discount' => '0', 'pricing_action' => 'automatic', 'pricing_fingerprint' => $proposal['fingerprint']]]], (int) $argv[4], $quote);
        echo 'saved';
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }
        echo 'conflict';
    }
} elseif (($argv[3] ?? '') === 'pricing') {
    try {
        app(CommercialPricing::class)->save(['name' => 'Concurrent scale', 'lower' => '0', 'upper' => '3000', 'increment' => '36', 'is_active' => true,
            'revision' => 1, 'source_reference' => 'Isolated concurrency test'], (int) $argv[4], CommercialPriceScale::findOrFail($messageId));
        echo 'saved';
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }
        echo 'conflict';
    }
} elseif (($argv[3] ?? '') === 'pipeline') {
    config(['filesystems.disks.local.root' => storage_path('framework/testing/commerce-mariadb'), 'commerce.telegram_enabled' => false, 'commerce.notify_email' => null, 'queue.default' => 'sync']);
    app()->instance(SupplierDownloader::class, new class extends SupplierDownloader
    {
        public function download(): string
        {
            usleep(500000);

            return "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,100,Prueba aislada,10.00";
        }
    });
    echo app(SupplierPipeline::class)->fetch($messageId, true, true)->uuid;
} elseif (($argv[3] ?? '') === 'commerce') {
    config(['filesystems.disks.local.root' => storage_path('framework/testing/commerce-mariadb')]);
    try {
        app(SupplierSync::class)->apply(SupplierSyncRun::findOrFail($messageId), null);
        echo 'applied';
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }
        echo 'conflict';
    }
} else {
    echo app(OutboxProcessor::class)->process($messageId) ? 'claimed' : 'skipped';
}
