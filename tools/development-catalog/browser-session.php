<?php

require __DIR__.'/local.php';

use App\Support\AdminAuditLogger;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;

verifiedDevelopmentBackup($runRoot);
// A short-lived local review session for the EXISTING authorized administrator.
// No password is read/reset, no roles are granted and no authentication route changes.
$session = app('session')->driver();
$session->start();
Auth::guard()->login($actor, false);
$session->put('auth_session_version', $actor->session_version);
$session->put('development_catalog_review', 'october-2026');
$session->save();
$name = config('session.cookie');
$value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
writeDevelopmentReport('browser-access', ['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 1800, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []]);
app(AdminAuditLogger::class)->record('development_catalog.review_session_created', 'development_catalog', 'october-2026', after: ['environment' => 'local', 'browser_cookie_minutes' => 30], actorId: $actor->id);
echo 'Private local browser session prepared. No credentials changed or printed.'.PHP_EOL;
