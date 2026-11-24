<?php

// Operational QA only. Never accepts another worktree or a configured shared database.
use App\Models\CommercialPriceScale;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use App\Models\QuoteRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminAuditLogger;
use App\Support\BasePermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$root = strtr(dirname(__DIR__, 2), [chr(92) => '/']);
if (PHP_SAPI !== 'cli' || str_replace('\\', '/', realpath($root)) !== 'E:/proyectos/usbstock/api-grupotalento' || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Exact isolated worktree required.');
}
require $root.'/vendor/autoload.php';
$e = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
if (($e['APP_ENV'] ?? '') !== 'local' || ($e['DB_CONNECTION'] ?? '') !== 'sqlite'
    || str_replace('\\', '/', $e['DB_DATABASE'] ?? '') !== $root.'/database/api-grupotalento.sqlite' || ! empty($e['DB_URL'])) {
    throw new RuntimeException('Isolated SQLite required before bootstrap.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).($error::class === RuntimeException::class ? ': '.$error->getMessage() : '').PHP_EOL);
    exit(1);
});
if (! app()->environment('local') || config('database.default') !== 'sqlite'
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', database_path('api-grupotalento.sqlite'))
    || config('commerce.notify_email') || config('mail.default') !== 'array') {
    throw new RuntimeException('Runtime isolation or mail guard failed.');
}
foreach (['commerce.sync.ftp_enabled', 'commerce.sync.enabled', 'commerce.sync.auto_apply', 'commerce.telegram_enabled', 'commerce.corporate_contacts.record_clicks'] as $flag) {
    if (config($flag)) {
        throw new RuntimeException('External writes must remain disabled.');
    }
}
$qa = storage_path('app/private/qa/api-commercial-validation');
$backup = json_decode(file_get_contents($qa.'/backup.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($backup['backup_sha256'], hash_file('sha256', $qa.'/before-commercial.sqlite'))) {
    throw new RuntimeException('Verified backup required.');
}
function report(string $name, array $value): void
{
    global $qa;
    file_put_contents($qa.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($qa.'/context.json')) {
        throw new RuntimeException('Context already prepared; preserve it.');
    }
    if (Quote::count() !== 1 || QuoteRequest::exists() || User::count() !== 1) {
        throw new RuntimeException('Unexpected existing commercial data.');
    }
    $variant = ProductVariant::with(['product.image', 'product.category'])->where('sku', 'USB017-AZ')->sole();
    if (! $variant->product->isPublic() || ! $variant->product->image || $variant->stock < 100 || $variant->supplier_price_minor <= 0) {
        throw new RuntimeException('Expected eligible development variant missing.');
    }
    $permissions = ['dashboard.view', 'products.view', 'quotes.view', 'quotes.manage', 'quotes.issue', 'quote_requests.manage', 'sellers.manage', 'commerce.configure', 'clients.manage'];
    foreach ($permissions as $key) {
        Permission::firstOrCreate(['key' => $key], ['description' => BasePermissions::DEFINITIONS[$key]]);
    }
    $ids = Permission::whereIn('key', $permissions)->pluck('id');
    if ($ids->count() !== count($permissions)) {
        throw new RuntimeException('Structural permissions missing.');
    }
    $actor = DB::transaction(function () use ($ids) {
        $role = Role::create(['key' => 'qa_api_commercial', 'name' => 'QA API comercial local', 'description' => 'Permisos para el ensayo ficticio autorizado; no producción.', 'is_active' => true]);
        $role->permissions()->sync($ids);
        $actor = User::create(['name' => 'Administrador ficticio QA API', 'email' => 'qa-api-admin@example.invalid', 'password' => bin2hex(random_bytes(32)), 'is_active' => true]);
        $actor->roles()->attach($role);

        return $actor;
    });
    $run = (string) Str::uuid();
    $context = ['run_uuid' => $run, 'actor_id' => $actor->id, 'customer_name' => 'Cliente ficticio QA API '.substr($run, 0, 8), 'customer_email' => 'qa-api-commercial@example.invalid', 'seller_name' => 'Asesor ficticio QA API '.substr($run, 0, 8),
        'product' => ['slug' => $variant->product->slug, 'variant_id' => $variant->id, 'variant_uuid' => $variant->uuid, 'sku' => $variant->sku, 'name' => $variant->product->name, 'stock' => $variant->stock, 'cost_minor' => $variant->supplier_price_minor],
        'scale_name' => 'QA API ficticia 25% '.substr($run, 0, 8), 'scale_is_fictional' => true, 'database' => 'api-grupotalento.sqlite', 'external_writes_disabled' => true];
    report('context', $context);
    app(AdminAuditLogger::class)->record('qa.api_commercial_actor_prepared', 'qa_api', $run, after: ['fictional' => true, 'local_only' => true], actorId: $actor->id);
    echo json_encode(['prepared' => true, 'actor_fictional' => true, 'scale_count' => CommercialPriceScale::count(), 'variant_eligible' => true]),PHP_EOL;
    exit;
}
$context = json_decode(file_get_contents($qa.'/context.json'), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'session') {
    $actor = User::findOrFail($context['actor_id']);
    if ($actor->email !== 'qa-api-admin@example.invalid' || ! $actor->hasPermissionTo('quotes.issue')) {
        throw new RuntimeException('Fictional actor required.');
    }
    $session = app('session')->driver();
    $session->start();
    Auth::guard()->login($actor, false);
    $session->put('auth_session_version', $actor->session_version);
    $session->put('qa_api_commercial', $context['run_uuid']);
    $session->save();
    $name = config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);
    report('browser-access', ['cookies' => [['name' => $name, 'value' => $value, 'domain' => '127.0.0.1', 'path' => '/', 'expires' => time() + 3600, 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax']], 'origins' => []]);
    echo 'Private fictional session prepared.',PHP_EOL;
    exit;
}
if (! in_array($mode, ['state', 'grant'], true) || ! Str::isUuid($argv[2] ?? '')) {
    throw new RuntimeException('Unknown QA action.');
}
$quote = Quote::with(['items', 'versions', 'sourceRequest'])->where('uuid', $argv[2])->sole();
if ($quote->created_by !== $context['actor_id'] || ($quote->customer['email'] ?? '') !== $context['customer_email'] || $quote->customer['name'] !== $context['customer_name'] || ! $quote->sourceRequest || $quote->sourceRequest->customer['email'] !== $context['customer_email']) {
    throw new RuntimeException('Only this QA quote is authorized.');
}
if ($mode === 'grant') {
    $old = $backup['fixture_3000']['quote_uuid'];
    $allowed = config('quote_api.allowed_quote_uuids');
    if (array_diff($allowed, [$old, $quote->uuid]) || ! in_array($old, $allowed, true)) {
        throw new RuntimeException('Unexpected API scope.');
    }
    $value = implode(',', array_values(array_unique([$old, $quote->uuid])));
    $env = file_get_contents(base_path('.env'));
    $next = preg_replace('/^USBSTOCK_API_ALLOWED_QUOTE_UUIDS=.*$/m', 'USBSTOCK_API_ALLOWED_QUOTE_UUIDS='.$value, $env, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Single API scope setting required.');
    }
    if ($next !== $env) {
        file_put_contents(base_path('.env'), $next, LOCK_EX);
    }
    report('scope-grant', ['only_existing_fixture_and_new_qa' => true, 'quote_uuid' => $quote->uuid]);
    echo 'QA quote scope granted; other private settings preserved.',PHP_EOL;
    exit;
}
$stage = $argv[3] ?? '';
if (! in_array($stage, ['draft', 'issued-v1', 'revision', 'issued-v2'], true)) {
    throw new RuntimeException('Unknown evidence stage.');
}
$mapping = QuotePublicIdentifier::where('quote_id', $quote->id)->first();
$state = ['quote' => $quote->toArray(), 'public_id' => $mapping?->public_id, 'versions' => $quote->versions->sortBy('version')->values()->toArray(), 'outbox_count' => DB::table('outbox_messages')->count()];
foreach ($state['versions'] as $version) {
    $file = Storage::disk('local')->path($version['path']);
    if (! is_file($file) || ! hash_equals($version['sha256'], hash_file('sha256', $file))) {
        throw new RuntimeException('PDF integrity failed.');
    }
}
report('state-'.$stage, $state);
echo json_encode(['stage' => $stage, 'status' => $quote->status, 'public_id' => $mapping?->public_id, 'versions' => count($state['versions']), 'outbox_count' => $state['outbox_count']]),PHP_EOL;
