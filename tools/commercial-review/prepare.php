<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$app = require __DIR__.'/bootstrap.php';
use App\Commerce\LegacyProductPhoto;
use App\Commerce\SupplierCsv;
use App\Models\CommercialSeller;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (Artisan::call('migrate', ['--pretend' => true, '--force' => true]) !== 0) throw new RuntimeException('QA migration review failed.');
file_put_contents($reviewRoot.'/migration-preview.txt', Artisan::output());
if (Artisan::call('migrate', ['--force' => true]) !== 0) throw new RuntimeException('QA migrations failed.');
if (is_file($reviewRoot.'/summary.json')) { echo "QA database already prepared. Existing review data preserved.\n"; exit; }
$baseline = $project.'/storage/app/private/qa/csv-policy/provider-original.csv';
echo "Validating historical CSV.\n"; $parsed = app(SupplierCsv::class)->parse(file_get_contents($baseline));
if ($parsed['errors'] || count($parsed['rows']) !== 818 || count(array_unique(array_column($parsed['rows'],'model'))) !== 362) throw new RuntimeException('Historical CSV does not match the reviewed baseline.');
$legacy = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($reviewRoot.'/legacy-photo-map.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$bySku = [];
foreach ($legacy as $row) $bySku[$row['sku']][] = $row;
$photoRoots = ['codexv0' => 'C:/xampp/htdocs/usbstock/codexv0/img/contenido/productos', 'codex' => 'C:/xampp/htdocs/usbstock/codex/img/contenido/productos'];
echo "Inspecting local photographs.\n"; $inventory = [];
foreach ($photoRoots as $source => $root) {
    foreach (scandir($root) as $name) { $file = $root.'/'.$name;
        if (! is_file($file)) continue;
        $info = @getimagesize($file);
        $inventory[$source][] = ['name' => basename($file), 'bytes' => filesize($file), 'sha256' => hash_file('sha256',$file), 'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'mime' => $info['mime'] ?? null];
    }
}
$byFile = array_column($inventory['codexv0'], null, 'name');
$mapping = [];
foreach ($parsed['rows'] as $row) {
    $matches = $bySku[$row['sku']] ?? [];
    $entry = ['model' => $row['model'], 'sku' => $row['sku'], 'status' => 'missing_legacy_mapping'];
    if (count($matches) > 1) $entry['status'] = 'ambiguous_sku';
    elseif (count($matches) === 1 && $matches[0]['model'] === $row['model']) {
        $id = (int) $matches[0]['id'];
        $photo = $byFile[$id.'.jpg'] ?? null;
        $entry += ['source' => 'codexv0', 'legacy_variant_id' => $id];
        $entry['status'] = $photo && $photo['mime'] === 'image/jpeg' ? 'verified' : 'missing_local_photo';
        if ($photo) $entry += $photo;
    }
    $mapping[] = $entry;
}
file_put_contents($reviewRoot.'/photo-inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($reviewRoot.'/photo-mapping.json', json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
Artisan::call('db:seed', ['--class' => Database\Seeders\PermissionsSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => Database\Seeders\RolesSeeder::class, '--force' => true]);
echo "Preparing isolated records.\n"; $password = Str::random(32);
$actor = User::firstOrCreate(['email' => 'costos@example.test'], ['name' => 'Costos · Revisión QA', 'email' => 'costos@example.test', 'password' => $password, 'is_active' => true]);
$actor->roles()->syncWithoutDetaching(Role::where('key','administrator')->sole());
if ($actor->wasRecentlyCreated) file_put_contents($reviewRoot.'/access.json', json_encode(['email' => $actor->email, 'password' => $password], JSON_PRETTY_PRINT));
CommercialSeller::firstOrCreate(['name' => 'José Luis · Vendedor de prueba', 'is_active' => true]);
CommercialSeller::firstOrCreate(['name' => 'Ana · Vendedora de prueba', 'is_active' => true]);
CommercialSetting::firstOrCreate(['id' => 1], ['id' => 1, 'data' => ['issuer_name' => 'USB Stock · Emisor de prueba', 'issuer_contact' => 'Revisión local sin validez comercial · ventas@example.test', 'terms' => 'Documento de prueba. Precios y existencias ficticios. No constituye una oferta comercial.', 'public_email' => 'ventas@example.test', 'about' => 'Catálogo visual aislado con nombres e imágenes históricos y disponibilidad ficticia.']]);
$categories = [];
foreach (['Memorias USB','Tecnología','Accesorios','Más ideas'] as $i => $name) $categories[$i] = ProductCategory::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name.' · QA', 'slug' => Str::slug($name), 'is_published' => true]);
$products = []; DB::beginTransaction();
foreach ($parsed['rows'] as $row) {
    if (! isset($products[$row['model']])) {
        $capacity = preg_match('/\b(\d+)\s*GB\b/i', $row['supplier_description'], $m) ? (int)$m[1] : null;
        $name = trim(preg_split('/[,.\r\n]/u', $row['supplier_description'])[0]);
        $products[$row['model']] = Product::firstOrCreate(['external_key' => $row['model']], ['external_key' => $row['model'], 'slug' => Str::slug($row['model']), 'custom_name' => Str::limit($name ?: $row['model'], 55), 'supplier_description' => $row['supplier_description'], 'capacity_gb' => $capacity, 'provider_active' => true, 'is_published' => true, 'product_category_id' => $categories[count($products)%4]->id]);
    }
    // Deliberately do not copy stock or price from March. All QA availability is synthetic.
    $products[$row['model']]->variants()->firstOrCreate(['sku' => $row['sku']], ['sku' => $row['sku'], 'color_code' => $row['color_code'], 'stock' => 250, 'supplier_price_minor' => 1000, 'supplier_description' => $row['supplier_description'], 'provider_active' => true, 'is_enabled' => true]);
}
DB::commit(); echo "Attaching verified covers.\n"; $attached = [];
foreach ($mapping as $entry) {
    if ($entry['status'] !== 'verified' || isset($attached[$entry['model']])) continue;
    // Explicit cover selection: first exact historical SKU in lexical order; variant recorded.
    $document = app(LegacyProductPhoto::class)->attach($products[$entry['model']], $entry, $photoRoots['codexv0'], $actor);
    $attached[$entry['model']] = ['document' => $document->uuid, 'sku' => $entry['sku'], 'legacy_variant_id' => $entry['legacy_variant_id']];
}
DB::table('commerce_locks')->where('id',1)->increment('catalog_revision');
$summary = ['mode' => 'isolated_visual_qa', 'baseline_sha256' => $parsed['sha256'], 'models' => count($products), 'variants' => count($parsed['rows']), 'covers' => count($attached), 'mapping_status' => array_count_values(array_column($mapping,'status')), 'photo_files' => array_map('count',$inventory), 'inventories_are_synthetic' => true, 'main_database_used' => false, 'network_used' => false];
file_put_contents($reviewRoot.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
file_put_contents($reviewRoot.'/covers.json', json_encode($attached, JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_PRETTY_PRINT)."\n";
