<?php

require __DIR__.'/local.php';

use App\Commerce\SupplierCsvComparison;
use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$action = $argv[1] ?? 'preflight';
$source = 'C:/xampp/htdocs/usbstock/Existencias-ftp-precio-20261021.csv';
$expectedHash = '80211b55bcc6caef329d09750c3d5dd0893105b5bf6c2f098f5c90ee913e4026';
if (! is_file($source) || ! hash_equals($expectedHash, hash_file('sha256', $source))) {
    throw new RuntimeException('The authorized October source changed. Review required.');
}
$backup = verifiedDevelopmentBackup($runRoot);
$preflight = $environment + ['actor_id' => $actor->id, 'backup_database_verified' => $backup['database_verified'],
    'backup_private_verified' => $backup['private_verified'], 'source_sha256' => $expectedHash,
    'source_bytes' => filesize($source), 'source_mtime_utc' => gmdate('c', filemtime($source)),
    'products' => Product::count(), 'variants' => ProductVariant::count(),
    'requests' => DB::table('quote_requests')->count(), 'quotes' => DB::table('quotes')->count(),
    'outbox' => DB::table('outbox_messages')->count(), 'network_used' => false];
Artisan::call('migrate:status');
file_put_contents($runRoot.'/migration-status.txt', Artisan::output());
$migrations = array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php')));
$applied = DB::table('migrations')->pluck('migration')->all();
if (array_diff($migrations, $applied) || array_diff($applied, $migrations)) {
    throw new RuntimeException('Migration inventory mismatch. No data application.');
}
$preflight['applied_migrations'] = count($applied);
writeDevelopmentReport('preflight-latest', $preflight);
$sync = app(SupplierSync::class);
if ($action === 'preflight') {
    echo json_encode($preflight, JSON_PRETTY_PRINT).PHP_EOL;
    exit;
}
if ($action === 'preview') {
    if (is_file($runRoot.'/import-run.json')) {
        throw new RuntimeException('A reviewed staging run already exists; use its explicit apply action.');
    }
    $bytes = file_get_contents($source);
    $copy = $runRoot.'/provider-october-original.csv';
    if (is_file($copy) && ! hash_equals($expectedHash, hash_file('sha256', $copy))) {
        throw new RuntimeException('The private original differs. Nothing overwritten.');
    }
    file_put_contents($copy, $bytes);
    $comparison = app(SupplierCsvComparison::class)->compare(file_get_contents(storage_path('app/private/qa/csv-policy/provider-original.csv')), $bytes);
    writeDevelopmentReport('comparison-march-october', $comparison);
    $run = $sync->preview($bytes, basename($source), $actor->id);
    $data = $sync->rows($run);
    $report = ['uuid' => $run->uuid, 'status' => $run->status, 'sha256' => $run->sha256,
        'row_count' => $run->row_count, 'summary' => $run->summary, 'errors' => $run->errors,
        'zero_stock_unique' => count(array_filter($data['rows'], fn ($row) => $row['stock'] === 0)),
        'total_stock' => array_sum(array_column($data['rows'], 'stock')), 'source' => $preflight,
        'comparison_suspicious' => $comparison['changes']['suspicious'] ?? true];
    writeDevelopmentReport('import-run', $report);
    $safeSummary = array_intersect_key($run->summary, array_flip(['policy_version', 'received_count', 'unique_count', 'model_count', 'new', 'changed', 'missing', 'warnings', 'error_count', 'suspicious', 'pending_content']));
    echo json_encode(['uuid' => $run->uuid, 'status' => $run->status, 'zero_stock_unique' => $report['zero_stock_unique'], 'summary' => $safeSummary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($run->status === 'ready' && ! $report['comparison_suspicious'] ? 0 : 1);
}
if ($action !== 'apply') {
    throw new RuntimeException('Allowed actions: preflight, preview, apply.');
}
$review = json_decode(file_get_contents($runRoot.'/import-run.json'), true, flags: JSON_THROW_ON_ERROR);
if ($review['status'] !== 'ready' || $review['errors'] || $review['summary']['suspicious'] || $review['comparison_suspicious']
    || ! hash_equals($expectedHash, $review['sha256'])) {
    throw new RuntimeException('The staged file requires review. Application blocked.');
}
$run = SupplierSyncRun::where('uuid', $review['uuid'])->sole();
$run = $sync->apply($run, $actor->id); // Existing staging/hash/revision/transaction/idempotency guards.
$report = ['uuid' => $run->uuid, 'status' => $run->status, 'sha256' => $run->sha256, 'summary' => $run->summary,
    'products' => Product::count(), 'variants' => ProductVariant::count(), 'published' => Product::where('is_published', true)->count(),
    'outbox' => DB::table('outbox_messages')->count(), 'applied_at' => $run->applied_at?->toIso8601String(), 'network_used' => false];
writeDevelopmentReport('application', $report);
echo json_encode(collect($report)->except('summary')->all(), JSON_PRETTY_PRINT).PHP_EOL;
