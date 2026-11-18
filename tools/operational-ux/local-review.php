<?php

// Explicitly authorized operational QA, not a test runner or fixture seeder.
// Reuses the strict local destination / disabled outbound guards; no inventory writes.
require dirname(__DIR__).'/development-catalog/local.php';

use App\Models\ProductVariant;
use App\Models\QuoteVersion;
use App\Support\AdminAuditLogger;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

$root = storage_path('app/private/qa/operational-ux');
File::ensureDirectoryExists($root, 0700);
$mode = $argv[1] ?? '';
if (! in_array($mode, ['before', 'after', 'session'], true)) {
    throw new RuntimeException('Use before, after or session.');
}
if ($mode === 'session') {
    verifiedDevelopmentBackup($runRoot);
    $session = app('session')->driver();
    $session->start();
    Auth::guard()->login($actor, false);
    $session->put('auth_session_version', $actor->session_version);
    $session->save();
    $name = config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
    file_put_contents($root.'/browser-access.json', json_encode(['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 1800, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []], JSON_THROW_ON_ERROR));
    app(AdminAuditLogger::class)->record('operational_ux.review_session_created', 'operational_ux', 'ajax-pagination', after: ['environment' => 'local', 'browser_cookie_minutes' => 30], actorId: $actor->id);
    echo "Private local review session ready; credentials unchanged.\n";
    exit;
}
$report = ['environment' => $environment, 'database' => $identity->name, 'tables' => [], 'pdfs' => [], 'products' => []];
foreach (['products', 'product_variants', 'product_categories', 'documents', 'quotes', 'quote_versions', 'commercial_settings', 'commercial_clients', 'commercial_sellers', 'outbox_messages', 'failed_jobs'] as $table) {
    $rows = DB::table($table)->orderBy('id')->get();
    $report['tables'][$table] = ['count' => $rows->count(), 'sha256' => hash('sha256', $rows->toJson())];
}
foreach (QuoteVersion::all() as $version) {
    $report['pdfs'][$version->uuid] = hash_file('sha256', Storage::disk('local')->path($version->path));
}
foreach (['USB017-AZ', 'USB027-AZ'] as $sku) {
    $variant = ProductVariant::with('product')->where('sku', $sku)->sole();
    if (! $variant->product->isPublic() || $variant->stock < 100) {
        throw new RuntimeException('Review variant unavailable.');
    }
    $report['products'][] = ['slug' => $variant->product->slug, 'sku' => $sku, 'variant_uuid' => $variant->uuid];
}
$report['request_count'] = DB::table('quote_requests')->count();
$report['category_slug'] = DB::table('product_categories')->where('is_published', true)->value('slug');
$report['catalog_uuid'] = DB::table('catalogs')->value('uuid');
if ($mode === 'after') {
    $before = json_decode(file_get_contents($root.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
    $report['protected_data_unchanged'] = $before['tables'] === $report['tables'] && $before['pdfs'] === $report['pdfs'];
    $report['new_requests'] = $report['request_count'] - $before['request_count'];
    if (! $report['protected_data_unchanged']) {
        throw new RuntimeException('Protected development data changed; inspect locally.');
    }
    if ($report['new_requests'] !== 2) {
        throw new RuntimeException('Expected exactly two marked operational review requests.');
    }
} elseif (is_file($root.'/before.json')) {
    throw new RuntimeException('Baseline already exists; keep the original evidence.');
}
file_put_contents($root.'/'.$mode.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['stage' => $mode, 'safe' => true, 'models' => $report['tables']['products']['count'], 'variants' => $report['tables']['product_variants']['count'], 'request_count' => $report['request_count'], 'protected_data_unchanged' => $report['protected_data_unchanged'] ?? null]).PHP_EOL;
