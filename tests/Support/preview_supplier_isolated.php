<?php

// Operational QA helper: no network, no persistent database, no apply mode.
use App\Commerce\SupplierSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDatabaseSafety;

$base = dirname(__DIR__, 2);
$input = $argv[1] ?? '';
if (! is_file($input) || filesize($input) > 8 * 1024 * 1024) {
    throw new RuntimeException('Provide an existing local CSV of up to 8 MiB.');
}
foreach (['APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'SUPPLIER_FTP_ENABLED' => 'false', 'SUPPLIER_SYNC_ENABLED' => 'false', 'SUPPLIER_SYNC_AUTO_APPLY' => 'false',
    'SUPPLIER_TELEGRAM_ENABLED' => 'false', 'TELEGRAM_ENABLED' => 'false', 'COMMERCE_NOTIFY_EMAIL' => '', 'OUTBOX_MARIADB_TEST' => '0'] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}
require $base.'/tests/bootstrap.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::install($app);
Http::preventStrayRequests();
$folder = $base.'/storage/app/private/qa/csv-policy';
if (! is_dir($folder)) {
    mkdir($folder, 0775, true);
}
config(['filesystems.disks.local.root' => $folder.'/staging']);
Artisan::call('migrate', ['--force' => true]); // Guarded SQLite :memory: only.
$sync = app(SupplierSync::class);
$bytes = file_get_contents($input);
$physicalLines = preg_match_all('/\r\n|\r|\n/', $bytes) + (preg_match('/[\r\n]\z/', $bytes) ? 0 : 1);
$run = $sync->preview($bytes, basename($input), null);
$data = $sync->rows($run);
$summary = $run->summary;
$report = [
    'database' => 'sqlite::memory:', 'status' => $run->status, 'uuid' => $run->uuid, 'sha256' => $run->sha256,
    'received' => $summary['received_count'], 'unique' => $summary['unique_count'], 'models' => $summary['model_count'],
    'physical_lines_including_header' => $physicalLines, 'physical_data_lines' => max(0, $physicalLines - 1),
    'duplicates_consolidated' => $summary['duplicates_consolidated'], 'empty_descriptions' => $summary['empty_descriptions'],
    'zero_stock_unique' => count(array_filter($data['rows'], fn ($row) => $row['stock'] === 0)),
    'new_models' => $summary['new_models'], 'new_variants' => $summary['new'],
    'changed' => $summary['changed'], 'missing' => $summary['missing'], 'suspicious' => $summary['suspicious'],
    'pending_content' => $summary['pending_content'], 'errors' => $run->errors, 'warnings' => $summary['warnings'],
    'catalog_products_after_preview' => DB::table('products')->count(), 'catalog_revision' => DB::table('commerce_locks')->value('catalog_revision'),
    'private_csv' => Storage::disk('local')->path($run->path), 'automatic_publication' => false,
];
file_put_contents($folder.'/real-preview.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// Persist complete provenance privately, without returning any supplier costs.
file_put_contents($folder.'/real-preview-provenance.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($run->status === 'ready' ? 0 : 1);
