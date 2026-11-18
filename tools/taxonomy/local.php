<?php

require dirname(__DIR__).'/development-catalog/local.php';

use App\Commerce\LegacyTaxonomy;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\AdminAuditLogger;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$root = storage_path('app/private/taxonomy-20261118');
$mode = $argv[1] ?? 'preview';
if (! in_array($mode, ['migrate', 'preview', 'apply', 'verify', 'browser'], true)) {
    throw new RuntimeException('Invalid operation.');
}
if (config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('External contacts must remain disabled.');
}
$manifest = json_decode(file_get_contents($root.'/backup-before-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (! $manifest['permissions_verified'] || ! hash_equals($manifest['sha256'], hash_file('sha256', $root.'/backup-before.json'))) {
    throw new RuntimeException('Verified backup required.');
}
if ($mode === 'browser') {
    $session = app('session')->driver();
    $session->start();
    Auth::guard()->login($actor, false);
    $session->put('auth_session_version', $actor->session_version);
    $session->save();
    $name = config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
    file_put_contents($root.'/browser-access.json', json_encode(['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 1800, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/browser-expected.json', json_encode(['eligible' => Product::catalogVisible()->orderByDesc('id')->pluck('uuid')->all(), 'categories' => ProductCategory::orderBy('id')->get(['slug', 'name', 'parent_id'])->toArray()], JSON_THROW_ON_ERROR));
    app(AdminAuditLogger::class)->record('taxonomy.review_session_created', 'taxonomy', 'categories-whatsapp', after: ['local_only' => true, 'minutes' => 30], actorId: $actor->id);
    echo "Private local review session prepared.\n";
    exit;
}
if ($mode === 'migrate') {
    $exit = Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_18_000001_add_commercial_taxonomy.php', '--force' => true]);
    echo Artisan::output();
    exit($exit);
}
$source = json_decode(file_get_contents($root.'/legacy-taxonomy.json'), true, flags: JSON_THROW_ON_ERROR);
$service = app(LegacyTaxonomy::class);
$plan = $service->preview($source);
if ($mode === 'apply') {
    $preview = json_decode(file_get_contents($root.'/preview.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($plan !== $preview) {
        throw new RuntimeException('Preview changed; review it again.');
    }
    $result = $service->apply($source, $actor->id);
    $repeat = $service->apply($source, $actor->id);
    if ($repeat['categories_created'] || $repeat['relations_created']) {
        throw new RuntimeException('Import was not idempotent.');
    }
    $result['idempotent'] = true;
} else {
    $result = $mode === 'preview' ? $plan : ['categories' => DB::table('product_categories')->count(), 'relations' => DB::table('product_category_assignments')->count(), 'classified_models' => $plan['classified_models'], 'unclassified' => $plan['unclassified'], 'eligible_models' => Product::catalogVisible()->count(), 'source_sha256' => $plan['source_sha256']];
}
file_put_contents($root.'/'.$mode.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($mode === 'preview' ? ['categories' => count($plan['categories']), 'relations' => count($plan['assignments']), 'models' => $plan['classified_models'], 'unclassified' => $plan['unclassified'], 'ambiguous' => $plan['ambiguous']] : $result, JSON_UNESCAPED_UNICODE).PHP_EOL;
