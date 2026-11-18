<?php

require dirname(__DIR__).'/development-catalog/local.php';

use App\Commerce\CommercialPricing;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Support\AdminAuditLogger;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$root = storage_path('app/private/taxonomy-20261118/admin-repair');
if (! is_dir($root)) {
    mkdir($root, 0700, true);
}
$mode = $argv[1] ?? 'before';
if ($mode === 'session') {
    if (! is_file($root.'/before.json')) {
        throw new RuntimeException('Capture the baseline first.');
    }
    $session = app('session')->driver();
    $session->start();
    Auth::guard()->login($actor, false);
    $session->put('auth_session_version', $actor->session_version);
    $session->save();
    $name = config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
    file_put_contents($root.'/browser-access.json', json_encode(['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 3600, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/session-id', $session->getId());
    app(AdminAuditLogger::class)->record('admin_repair.review_session_created', 'admin_review', 'responsive-pricing', after: ['environment' => 'local', 'minutes' => 60], actorId: $actor->id);
    echo "Private review session ready; existing credentials unchanged.\n";
    exit;
}
if ($mode === 'close') {
    if (is_file($root.'/session-id')) {
        app('session')->driver()->getHandler()->destroy(trim(file_get_contents($root.'/session-id')));
        unlink($root.'/session-id');
        unlink($root.'/browser-access.json');
    }
    echo "Review session removed.\n";
    exit;
}
if (! in_array($mode, ['before', 'after'], true) || ($mode === 'before' && is_file($root.'/before.json'))) {
    throw new RuntimeException('Invalid mode or existing baseline.');
}
$report = ['tables' => [], 'pdfs' => [], 'env_sha256' => hash_file('sha256', base_path('.env')), 'flags' => $environment['flags']];
DB::statement('START TRANSACTION READ ONLY');
try {
    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];
        if (in_array($table, ['cache', 'cache_locks', 'sessions', 'admin_audit_events', 'users'], true)) {
            continue;
        }
        $rows = DB::table($table)->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->all();
        $report['tables'][$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
    foreach (QuoteVersion::all() as $version) {
        $report['pdfs'][$version->uuid] = hash_file('sha256', Storage::disk('local')->path($version->path));
    }
    $quote = Quote::where('uuid', '01a1ed9e-eb26-72cf-8bce-af9b244d4a72')->sole();
    $report['quote'] = ['uuid' => $quote->uuid, 'status' => $quote->status, 'revision' => $quote->revision, 'items' => $quote->items->map(fn ($i) => ['sku' => $i->product['sku'], 'quantity' => $i->quantity, 'saved_minor' => $i->unit_minor, 'proposal' => app(CommercialPricing::class)->proposal($i->variant, $i->quantity)])->all()];
} finally {
    DB::statement('ROLLBACK');
}
if ($mode === 'after') {
    $before = json_decode(file_get_contents($root.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['tables', 'pdfs', 'env_sha256', 'flags'] as $key) {
        if ($before[$key] !== $report[$key]) {
            throw new RuntimeException('Unexpected development change: '.$key);
        }
    }
}
file_put_contents($root.'/'.$mode.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['tables' => count($report['tables']), 'pdfs' => count($report['pdfs']), 'verified' => true, 'quote' => $report['quote']]).PHP_EOL;
