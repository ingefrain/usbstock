<?php

require __DIR__.'/local.php';

use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

verifiedDevelopmentBackup($runRoot);
$application = json_decode(file_get_contents($runRoot.'/application.json'), true, flags: JSON_THROW_ON_ERROR);
$run = SupplierSyncRun::where('uuid', $application['uuid'])->sole();
$sync = app(SupplierSync::class);
$rows = $sync->rows($run)['rows'];
$variants = ProductVariant::with('product')->get()->keyBy('sku');
$require = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$require($run->status === 'completed' && count($rows) === $variants->count(), 'Import count/status differs.');
foreach ($rows as $row) {
    $variant = $variants->get($row['sku']);
    $require($variant && $variant->product->external_key === $row['model']
        && $variant->stock === $row['stock'] && $variant->supplier_price_minor === $row['supplier_price_minor']
        && $variant->supplier_description === $row['supplier_description'], 'CSV/catalog discrepancy.');
}
$fingerprint = fn () => hash('sha256', json_encode([
    DB::table('products')->orderBy('id')->get(), DB::table('product_variants')->orderBy('id')->get(),
], JSON_THROW_ON_ERROR));
$before = $fingerprint();
$sync->apply($run, $actor->id);
$require(hash_equals($before, $fingerprint()), 'Completed import was not idempotent.');
$disk = Storage::disk('local');
$photos = 0;
foreach (Product::with('image')->get() as $product) {
    $description = $run->summary['model_description_sources'][$product->external_key]['description_sha256'];
    $require(hash_equals($description, hash('sha256', $product->supplier_description)), 'Model description differs.');
    if (! $document = $product->image) {
        continue;
    }
    $require(hash_equals($document->checksum, hash_file('sha256', $disk->path($document->path))), 'WebP checksum differs.');
    $require(hash_equals($document->metadata['legacy_source_sha256'], hash_file('sha256', $disk->path($document->metadata['original_private_path']))), 'Preserved original differs.');
    $thumbnail = $disk->path('product-thumbnails/v1/'.$document->checksum.'.webp');
    $info = is_file($thumbnail) ? getimagesize($thumbnail) : false;
    $require($info && $info['mime'] === 'image/webp' && max($info[0], $info[1]) <= 480, 'Thumbnail missing or invalid.');
    $photos++;
}
$report = ['checked_at' => now()->toIso8601String(), 'environment' => $environment, 'run_uuid' => $run->uuid,
    'source_sha256' => $run->sha256, 'products' => Product::count(), 'variants' => $variants->count(),
    'published' => Product::catalogVisible()->count(), 'zero_stock' => $variants->where('stock', 0)->count(),
    'total_stock' => $variants->sum('stock'), 'covers_verified' => $photos, 'catalog_sha256' => $before,
    'csv_catalog_exact' => true, 'completed_run_idempotent' => true,
    'requests' => DB::table('quote_requests')->count(), 'quotes' => DB::table('quotes')->count(),
    'quote_versions' => DB::table('quote_versions')->count(), 'outbox' => DB::table('outbox_messages')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(), 'network_used' => false];
$require($report['outbox'] === 0, 'Unexpected outbound message.');
writeDevelopmentReport('verification', $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
