<?php

// Exact isolated instance of main. No environment file or production credentials.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Http;

$project = str_replace('\\', '/', dirname(__DIR__, 2));
$reviewCachePath = 'storage/app/private/qa/api-postmerge';
$reviewRoot = $project.'/'.$reviewCachePath;
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true) || $project !== 'E:/proyectos/usbstock/site' || is_file($project.'/bootstrap/cache/config.php')
    || ! is_file($reviewRoot.'/manifest.json') || ! is_file($reviewRoot.'/instance.json')) {
    throw new RuntimeException('Prepared isolated main instance required.');
}
$instance = json_decode(file_get_contents($reviewRoot.'/instance.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($reviewRoot.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$database = $reviewRoot.'/review.sqlite';
if (($instance['purpose'] ?? '') !== 'api-postmerge-isolated-v1' || ($instance['database'] ?? '') !== $database
    || ! ($manifest['ok'] ?? false) || ! is_file($database) || is_link($database)
    || ($instance['port'] ?? null) !== 4398 || ($instance['commit'] ?? '') !== ($manifest['commit'] ?? null)) {
    throw new RuntimeException('Isolated instance mismatch.');
}
$environment = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_NAME' => 'USB Stock QA postmerge', 'APP_KEY' => $instance['key'], 'APP_PREVIOUS_KEYS' => '', 'APP_URL' => 'http://127.0.0.1:4398',
    // Laravel resolves these relative to basePath; Windows drive letters are not absolute cache prefixes.
    'APP_CONFIG_CACHE' => $reviewCachePath.'/unused-config.php', 'APP_ROUTES_CACHE' => $reviewCachePath.'/unused-routes.php', 'APP_SERVICES_CACHE' => $reviewCachePath.'/services.php', 'APP_PACKAGES_CACHE' => $reviewCachePath.'/packages.php', 'APP_EVENTS_CACHE' => $reviewCachePath.'/unused-events.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '', 'DB_SOCKET' => '',
    'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'usbstock_api_postmerge', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'usbstock_api_postmerge', 'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false',
    'SESSION_CONNECTION' => '', 'SESSION_STORE' => '', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'FILESYSTEM_DISK' => 'local', 'DOCUMENTS_DISK' => 'local',
    'SUPPLIER_FTP_ENABLED' => 'false', 'SUPPLIER_SYNC_ENABLED' => 'false', 'SUPPLIER_SYNC_AUTO_APPLY' => 'false', 'SUPPLIER_FTP_HOST' => '', 'SUPPLIER_FTP_USER' => '', 'SUPPLIER_FTP_PASSWORD' => '',
    'SUPPLIER_TELEGRAM_ENABLED' => 'false', 'TELEGRAM_ENABLED' => 'false', 'COMMERCE_NOTIFY_EMAIL' => '', 'CORPORATE_CONTACTS_ENABLED' => 'false', 'CORPORATE_CONTACTS_KEY' => '', 'CORPORATE_CONTACTS_RECORD_CLICKS' => 'false',
    'USBSTOCK_API_ENABLED' => 'false', 'USBSTOCK_API_PDF_ENABLED' => 'false', 'USBSTOCK_API_BEARER_TOKEN' => '', 'USBSTOCK_API_ALLOWED_QUOTE_UUIDS' => '', 'USBSTOCK_API_ENVIRONMENT' => 'local', 'USBSTOCK_API_BASE_URL' => 'http://127.0.0.1:4398',
];
foreach ($environment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
foreach (['unused-config.php', 'unused-routes.php', 'unused-events.php', 'never-load-main.env'] as $name) {
    if (file_exists($reviewRoot.'/'.$name)) {
        throw new RuntimeException('Unexpected runtime override file.');
    }
}
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->useEnvironmentPath($reviewRoot)->loadEnvironmentFrom('never-load-main.env');
$app->useStoragePath($reviewRoot.'/runtime');
// Config is loaded, providers are registered, and no provider boot method has run.
$app->afterBootstrapping(RegisterProviders::class, static function ($app) use ($database): void {
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections', ['sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'url' => null, 'foreign_key_constraints' => true, 'busy_timeout' => 5000]]);
    $app['config']->set('commerce.review_mode', true);
    $app['config']->set('services.telegram.enabled', false);
    $app['config']->set('notifications.telegram.enabled', false);
    $manager = $app->make('db');
    foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
        $manager->extend($driver, static function ($config, $name) use ($app, $database) {
            if (($config['driver'] ?? '') !== 'sqlite' || ($config['database'] ?? '') !== $database || ! empty($config['url']) || ! empty($config['read']) || ! empty($config['write']) || ! empty($config['unix_socket']) || ! empty($config['prefix'])) {
                throw new RuntimeException('Postmerge PDO isolation rejected connection.');
            }

            return (new ConnectionFactory($app))->make($config, $name);
        });
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
if (config('quote_api.enabled') !== false || config('quote_api.pdf_enabled') !== false || config('quote_api.bearer_token') !== '' || config('quote_api.allowed_quote_uuids') !== []
    || config('mail.default') !== 'array' || config('commerce.notify_email') || config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')
    || config('commerce.sync.enabled') || config('commerce.sync.ftp_enabled') || config('commerce.sync.auto_apply') || config('commerce.telegram_enabled')
    || str_replace('\\', '/', storage_path()) !== $reviewRoot.'/runtime') {
    throw new RuntimeException('Postmerge runtime guard failed.');
}

return $app;
