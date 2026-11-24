<?php

use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\QuoteVersion;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Only a private fictional admin session and read-only technical status are exposed.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': isolated postmerge action failed'.PHP_EOL);
    exit(1);
});
$app = require __DIR__.'/postmerge-bootstrap.php';
$mode = $argv[1] ?? '';
if ($mode === 'status') {
    $ok = DB::select('PRAGMA integrity_check')[0]->integrity_check === 'ok';
    echo json_encode(['integrity' => $ok, 'api_enabled' => config('quote_api.enabled'), 'database_isolated' => true, 'quotes' => Quote::count(), 'quote_versions' => QuoteVersion::count(), 'requests' => QuoteRequest::count(), 'outbox' => DB::table('outbox_messages')->count()]),PHP_EOL;
    exit($ok ? 0 : 1);
}
if ($mode !== 'session') {
    throw new RuntimeException('Unknown action.');
}
$actor = User::findOrFail($instance['actor_id']);
if ($actor->email !== 'qa-api-admin@example.invalid' || ! $actor->hasPermissionTo('quotes.view')) {
    throw new RuntimeException('Fictional actor required.');
}
$session = app('session')->driver();
$session->start();
Auth::guard()->login($actor, false);
$session->put('auth_session_version', $actor->session_version);
$session->save();
$name = config('session.cookie');
$value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
file_put_contents($reviewRoot.'/browser-access.json', json_encode(['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 3600, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []], JSON_THROW_ON_ERROR));
echo 'Private postmerge fictional session prepared.',PHP_EOL;
