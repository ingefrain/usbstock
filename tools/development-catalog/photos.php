<?php

require __DIR__.'/local.php';

use App\Commerce\LegacyProductPhoto;
use App\Commerce\ProductThumbnail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

$action = $argv[1] ?? 'inspect';
if (! in_array($action, ['inspect', 'attach'], true)) {
    throw new RuntimeException('Allowed actions: inspect, attach.');
}
verifiedDevelopmentBackup($runRoot);
echo 'Environment and backup verified.'.PHP_EOL;
$import = json_decode(file_get_contents($runRoot.'/application.json'), true, flags: JSON_THROW_ON_ERROR);
if (SupplierSyncRun::where('uuid', $import['uuid'])->sole()->status !== 'completed') {
    throw new RuntimeException('A completed authorized import is required.');
}
$roots = ['codexv0' => 'C:/xampp/htdocs/usbstock/codexv0/img/contenido/productos', 'codex' => 'C:/xampp/htdocs/usbstock/codex/img/contenido/productos'];
$sourceMap = $runRoot.'/legacy-photo-map.jsonl';
$legacy = [];
foreach (file($sourceMap, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $sku = str_replace(' ', '', strtoupper(trim($row['sku'])));
    $legacy[$sku][] = $row;
}
$inventory = [];
echo 'Exact historical mapping loaded.'.PHP_EOL;
if ($action === 'attach' && is_file($runRoot.'/photo-inventory.json')) {
    // The attachment service rechecks the selected original's SHA-256 on every new copy.
    $inventory = json_decode(file_get_contents($runRoot.'/photo-inventory.json'), true, flags: JSON_THROW_ON_ERROR);
} else {
    foreach ($roots as $source => $directory) {
        echo 'Reading '.$source.' photograph metadata.'.PHP_EOL;
        foreach (glob($directory.'/*.jpg') as $path) {
            $bytes = file_get_contents($path);
            $info = @getimagesizefromstring($bytes);
            $inventory[$source][basename($path)] = ['name' => basename($path), 'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes), 'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'mime' => $info['mime'] ?? null];
        }
        echo $source.': '.count($inventory[$source]).' files verified.'.PHP_EOL;
    }
}
$mapping = [];
echo 'Matching imported variants.'.PHP_EOL;
foreach (ProductVariant::with('product')->orderBy('sku')->get() as $variant) {
    $matches = $legacy[$variant->sku] ?? [];
    $entry = ['model' => $variant->product->external_key, 'sku' => $variant->sku, 'variant_id' => $variant->id, 'status' => 'missing_legacy_mapping'];
    if (count($matches) > 1) {
        $entry['status'] = 'ambiguous_sku';
    } elseif (count($matches) === 1) {
        $match = $matches[0];
        if ($match['model'] !== $entry['model'] || ! ctype_digit((string) $match['id'])) {
            $entry['status'] = 'model_or_id_mismatch';
        } else {
            $id = (int) $match['id'];
            $photo = $inventory['codexv0'][$id.'.jpg'] ?? null;
            $entry += ['source' => 'codexv0', 'legacy_variant_id' => $id];
            $entry['status'] = $photo && $photo['mime'] === 'image/jpeg' && $photo['bytes'] <= 5 * 1024 * 1024 ? 'verified' : 'missing_local_photo';
            if ($photo) {
                $entry += $photo;
            }
            $entry['secondary_reference'] = $inventory['codex'][$id.'.jpg'] ?? null;
        }
    }
    $mapping[] = $entry;
}
writeDevelopmentReport('photo-inventory', $inventory);
writeDevelopmentReport('photo-mapping', $mapping);
$byModel = [];
foreach ($mapping as $entry) {
    if ($entry['status'] === 'verified' && ! isset($byModel[$entry['model']])) {
        $byModel[$entry['model']] = $entry;
    }
}
$summary = ['source_map_sha256' => hash_file('sha256', $sourceMap), 'variants' => count($mapping),
    'statuses' => array_count_values(array_column($mapping, 'status')), 'models_with_verified_cover' => count($byModel),
    'models_without_verified_cover' => Product::whereNotIn('external_key', array_keys($byModel))->pluck('external_key')->all(),
    'cover_policy' => 'First exact verified SKU in lexical order; reference photograph, not color/stock approval.',
    'secondary_is_reference_only' => true, 'missing' => array_values(array_filter($mapping, fn ($entry) => $entry['status'] !== 'verified'))];
writeDevelopmentReport('photo-summary', $summary);
echo json_encode(collect($summary)->except('missing')->all(), JSON_PRETTY_PRINT).PHP_EOL;
if ($action === 'inspect') {
    exit;
}
$attached = $preserved = [];
$limit = max(1, (int) ($argv[2] ?? 10));
$created = 0;
$remaining = false;
foreach (Product::with('image')->orderBy('external_key')->get() as $product) {
    if (! isset($byModel[$product->external_key])) {
        continue;
    }
    $entry = $byModel[$product->external_key];
    if ($product->image && (($product->image->metadata['legacy_source_sha256'] ?? '') !== $entry['sha256']
        || ($product->image->metadata['legacy_sku'] ?? '') !== $entry['sku'])) {
        $preserved[] = $product->external_key;

        continue;
    }
    if (! $product->image && $created >= $limit) {
        $remaining = true;

        continue;
    }
    $created += $product->image ? 0 : 1;
    $document = $product->image ?? app(LegacyProductPhoto::class)->attach($product, $entry, $roots['codexv0'], $actor);
    app(ProductThumbnail::class)->response($document);
    $attached[$product->external_key] = ['document_uuid' => $document->uuid, 'sku' => $entry['sku'], 'legacy_variant_id' => $entry['legacy_variant_id'],
        'original_sha256' => $entry['sha256'], 'webp_sha256' => $document->checksum, 'path' => $document->path, 'mime' => $document->mime_type];
    writeDevelopmentReport('attached-covers', $attached);
    if (count($attached) % 40 === 0) {
        echo count($attached).' covers verified.'.PHP_EOL;
    }
}
if ($remaining) {
    echo 'Batch complete: '.count($attached).' verified covers; run attach again to continue.'.PHP_EOL;
    exit;
}
DB::transaction(function () use ($attached, $preserved, $actor): void {
    DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
    DB::table('commerce_locks')->where('id', 1)->increment('catalog_revision');
    app(AdminAuditLogger::class)->record('development_catalog.photos_reviewed', 'development_catalog', 'october-2026', after: ['covers' => count($attached), 'existing_preserved' => count($preserved), 'source' => 'exact_legacy_mapping', 'environment' => 'local'], actorId: $actor->id);
});
writeDevelopmentReport('photo-application', ['covers' => count($attached), 'preserved_existing' => $preserved, 'network_used' => false]);
echo 'Attached or already verified: '.count($attached).'; existing personal photos preserved: '.count($preserved).PHP_EOL;
