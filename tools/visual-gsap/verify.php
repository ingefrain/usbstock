<?php

use App\Commerce\PublicCatalog;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (! isset($app)) {
    $app = require __DIR__.'/bootstrap.php';
}
$connection = config('database.connections.'.config('database.default'));
if (config('database.default') !== 'sqlite' || ($connection['database'] ?? '') !== $reviewRoot.'/review.sqlite'
    || config('commerce.sync.ftp_enabled') || config('commerce.sync.enabled') || config('commerce.sync.auto_apply')
    || config('commerce.telegram_enabled') || config('services.telegram.enabled') || config('commerce.notify_email')
    || config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('The preview database or external-service isolation is not valid.');
}
$manifest = json_decode(file_get_contents($reviewRoot.'/snapshot-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$prepared = json_decode(file_get_contents($reviewRoot.'/prepared.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($manifest['snapshot_sha256'], $prepared['snapshot_sha256'])
    || ! hash_equals($manifest['snapshot_sha256'], hash_file('sha256', $reviewRoot.'/catalog-snapshot.jsonl'))) {
    throw new RuntimeException('The prepared snapshot does not match its source manifest.');
}
DB::statement('PRAGMA query_only=ON');
$counts = [];
foreach ($manifest['counts'] as $table => $expected) {
    if (! in_array($table, ['product_categories', 'products', 'product_variants', 'product_category_assignments', 'documents', 'commerce_locks'], true)) {
        throw new RuntimeException('Unexpected verification table.');
    }
    $counts[$table] = DB::table($table)->count();
    if ($counts[$table] !== $expected) {
        throw new RuntimeException('Isolated catalog counts changed.');
    }
}
$actualTables = [];
foreach (array_keys($counts) as $table) {
    $actualTables[$table] = DB::table($table)->get()->keyBy('id');
}
$verifiedRows = 0;
foreach (file($reviewRoot.'/catalog-snapshot.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $record = json_decode($line, flags: JSON_THROW_ON_ERROR);
    if ($record->kind !== 'row') {
        continue;
    }
    $actual = $actualTables[$record->table]->get($record->row->id);
    if (! $actual) {
        throw new RuntimeException('A catalog snapshot record is missing.');
    }
    foreach ($record->row as $column => $expected) {
        $value = $actual->{$column};
        $equal = is_array($expected) || is_object($expected)
            ? json_decode($value, flags: JSON_THROW_ON_ERROR) == $expected
            : (($value === null || $expected === null) ? $value === $expected : (string) $value === (string) $expected);
        if (! $equal) {
            // Never include field values: snapshot rows may contain private costs.
            throw new RuntimeException('A copied catalog field changed in '.$record->table.'.'.$column.'.');
        }
    }
    $verifiedRows++;
}
$foreignKeys = DB::select('PRAGMA foreign_key_check');
if ($foreignKeys) {
    throw new RuntimeException('The isolated snapshot contains invalid foreign keys.');
}
$photos = json_decode(file_get_contents($reviewRoot.'/photos-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($photos as $photo) {
    $file = $reviewRoot.'/runtime/app/private/'.$photo['path'];
    if (! is_file($file) || ! hash_equals($photo['sha256'], hash_file('sha256', $file))) {
        throw new RuntimeException('An isolated photograph changed.');
    }
}
$public = Product::catalogVisible();
$block = app(PublicCatalog::class)->block([]);
$visibleCount = $public->count();
if ($block['products']->count() !== min(24, $visibleCount) || DB::table('users')->exists()) {
    throw new RuntimeException('The public block contract or no-user-copy invariant failed.');
}
$report = [
    'database' => 'isolated_sqlite', 'source_database' => 'usbstock_dev', 'source_access' => 'READ ONLY snapshot',
    'snapshot_sha256' => $manifest['snapshot_sha256'], 'catalog_counts' => $counts,
    'visible_products' => $visibleCount, 'first_block_count' => $block['products']->count(),
    'has_next_block' => $block['next'] !== null, 'verified_photographs' => count($photos), 'verified_catalog_rows' => $verifiedRows,
    'foreign_keys_valid' => true, 'users_copied' => DB::table('users')->count(),
    'source_database_writes' => false, 'external_services_enabled' => false,
    'verified_utc' => gmdate('c'),
];
file_put_contents($reviewRoot.'/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
