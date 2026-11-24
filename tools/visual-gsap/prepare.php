<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/bootstrap.php';
$manifestPath = $reviewRoot.'/snapshot-manifest.json';
$snapshotPath = $reviewRoot.'/catalog-snapshot.jsonl';
if (! is_file($manifestPath) || ! is_file($snapshotPath)) {
    throw new RuntimeException('Export the read-only development catalog snapshot first.');
}
$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['source']['database'] ?? '') !== 'usbstock_dev' || ($manifest['source']['port'] ?? 0) !== 3307
    || ! hash_equals($manifest['snapshot_sha256'], hash_file('sha256', $snapshotPath))) {
    throw new RuntimeException('The private catalog snapshot is not verified.');
}
$tables = ['product_categories', 'products', 'product_variants', 'product_category_assignments', 'documents', 'commerce_locks'];
$records = array_fill_keys($tables, []);
foreach (file($snapshotPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $record = json_decode($line, flags: JSON_THROW_ON_ERROR);
    if ($record->kind === 'source') {
        continue;
    }
    if ($record->kind !== 'row' || ! array_key_exists($record->table, $records) || ! is_object($record->row)) {
        throw new RuntimeException('Unexpected snapshot row.');
    }
    // MariaDB JSON_OBJECT preserves JSON column types; Query Builder expects
    // encoded JSON strings when inserting directly without Eloquent casts.
    $row = (array) $record->row;
    foreach ($row as &$value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
    unset($value);
    $records[$record->table][] = $row;
}
foreach ($tables as $table) {
    if (count($records[$table]) !== $manifest['counts'][$table]) {
        throw new RuntimeException('The snapshot table count does not match its manifest.');
    }
}

if (Artisan::call('migrate', ['--pretend' => true, '--force' => true]) !== 0) {
    throw new RuntimeException('Isolated migration preview failed.');
}
file_put_contents($reviewRoot.'/migration-preview.txt', Artisan::output());
if (in_array('--review-migrations', $argv, true)) {
    echo "Reviewed migration SQL is stored in the private visual preview directory. No migrations or imports applied.\n";
    exit;
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Isolated SQLite migration failed.');
}
if (is_file($reviewRoot.'/prepared.json')) {
    require __DIR__.'/verify.php';
    exit;
}

// Source photographs are already managed catalog WebP files, never legacy URLs.
// Do not share storage: reading a thumbnail can generate a cached file.
$sourceRoot = realpath(dirname($project).'/site/storage/app/private');
$targetRoot = realpath($reviewRoot.'/runtime/app/private');
if (! $sourceRoot || ! $targetRoot || $sourceRoot === $targetRoot) {
    throw new RuntimeException('Independent photograph storage is required.');
}
$photos = [];
foreach ($records['documents'] as $document) {
    if ($document['disk'] !== 'local' || $document['category'] !== 'product.image' || $document['uploaded_by'] !== null
        || ! preg_match('/\Adocuments\/[a-f0-9-]{36}\.(webp|png|jpe?g)\z/i', $document['path'])
        || $document['path'] !== 'documents/'.$document['stored_name']
        || ! preg_match('/\A[a-f0-9]{64}\z/', $document['checksum'])) {
        throw new RuntimeException('A photograph does not meet the private managed-document contract.');
    }
    $source = realpath($sourceRoot.'/'.$document['path']);
    if (! $source || ! str_starts_with($source, $sourceRoot.DIRECTORY_SEPARATOR)
        || ! hash_equals($document['checksum'], hash_file('sha256', $source))
        || filesize($source) !== $document['size_bytes']) {
        throw new RuntimeException('A source photograph failed its path, size or checksum verification.');
    }
    $target = $targetRoot.'/'.$document['path'];
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    if (! is_file($target) && ! copy($source, $target)) {
        throw new RuntimeException('Could not copy a verified catalog photograph.');
    }
    if (! hash_equals($document['checksum'], hash_file('sha256', $target))) {
        throw new RuntimeException('A copied photograph failed checksum verification.');
    }
    $photos[] = ['path' => $document['path'], 'sha256' => $document['checksum']];
}

DB::transaction(function () use ($tables, $records): void {
    foreach (array_diff($tables, ['commerce_locks']) as $table) {
        if (DB::table($table)->exists()) {
            throw new RuntimeException('Preserve existing preview records; do not overwrite a partially prepared database.');
        }
    }
    $categories = $records['product_categories'];
    usort($categories, fn ($a, $b) => [! is_null($a['parent_id']), $a['id']] <=> [! is_null($b['parent_id']), $b['id']]);
    foreach ($categories as $category) {
        DB::table('product_categories')->insert($category);
    }
    foreach (['products', 'product_variants', 'product_category_assignments', 'documents'] as $table) {
        foreach (array_chunk($records[$table], 20) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
    DB::table('commerce_locks')->where('id', 1)->update($records['commerce_locks'][0]);
});
file_put_contents($reviewRoot.'/photos-manifest.json', json_encode($photos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($reviewRoot.'/prepared.json', json_encode([
    'snapshot_sha256' => $manifest['snapshot_sha256'], 'catalog_counts' => $manifest['counts'],
    'copied_photographs' => count($photos), 'source_database_writes' => false,
    'source_private_files_changed' => false, 'prepared_utc' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
require __DIR__.'/verify.php';
