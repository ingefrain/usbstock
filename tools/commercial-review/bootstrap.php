<?php
// Separate Laravel instance: no .env reading, no MariaDB connections, no shared storage.
$project = dirname(__DIR__, 2);
$reviewRoot = $project.'/storage/app/private/qa/commercial';
if (! is_dir($reviewRoot)) mkdir($reviewRoot, 0700, true);
if (is_file($project.'/bootstrap/cache/config.php')) throw new RuntimeException('Remove cached configuration before starting isolated review.');
$configPath = $reviewRoot.'/instance.json';
if (! is_file($configPath)) {
    if (PHP_SAPI !== 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'prepare.php') throw new RuntimeException('Run tools/commercial-review/prepare.php first.');
    file_put_contents($configPath, json_encode(['key' => 'base64:'.base64_encode(random_bytes(32)), 'database' => $reviewRoot.'/review.sqlite'], JSON_PRETTY_PRINT));
}
$instance = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
$database = $reviewRoot.'/review.sqlite';
if (($instance['database'] ?? '') !== $database) throw new RuntimeException('Unexpected QA database path.');
foreach (['app/private','framework/cache/data','framework/sessions','framework/views','logs'] as $dir) {
    if (! is_dir($reviewRoot.'/runtime/'.$dir)) mkdir($reviewRoot.'/runtime/'.$dir, 0700, true);
}
if (! is_file($database)) touch($database);
$environment = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_NAME' => 'USB Stock · Revisión aislada',
    'APP_KEY' => $instance['key'], 'APP_URL' => 'http://127.0.0.1:4388',
    'APP_CONFIG_CACHE' => $reviewRoot.'/unused-config.php', 'APP_ROUTES_CACHE' => $reviewRoot.'/unused-routes.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'usbstock_commercial_review',
    'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'FILESYSTEM_DISK' => 'local', 'DOCUMENTS_DISK' => 'local', 'BROADCAST_CONNECTION' => 'null',
    'SUPPLIER_FTP_ENABLED' => 'false', 'SUPPLIER_SYNC_ENABLED' => 'false', 'SUPPLIER_SYNC_AUTO_APPLY' => 'false',
    'SUPPLIER_FTP_HOST' => '', 'SUPPLIER_FTP_USER' => '', 'SUPPLIER_FTP_PASSWORD' => '',
    'SUPPLIER_TELEGRAM_ENABLED' => 'false', 'TELEGRAM_ENABLED' => 'false', 'COMMERCE_NOTIFY_EMAIL' => '',
];
foreach ($environment as $name => $value) { putenv($name.'='.$value); $_ENV[$name] = $_SERVER[$name] = $value; }
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->useEnvironmentPath($reviewRoot)->loadEnvironmentFrom('never-load-main.env');
$app->useStoragePath($reviewRoot.'/runtime');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['commerce.review_mode' => true, 'mail.default' => 'array', 'services.telegram.enabled' => false, 'notifications.telegram.enabled' => false,
    'commerce.sync.enabled' => false, 'commerce.sync.auto_apply' => false, 'commerce.sync.ftp_enabled' => false,
    'commerce.telegram_enabled' => false, 'commerce.notify_email' => null,
    'database.connections' => ['sqlite' => array_merge(config('database.connections.sqlite'), ['database' => $database, 'url' => null, 'foreign_key_constraints' => true])]]);
$manager = $app->make('db');
foreach (['sqlite','mysql','mariadb','pgsql','sqlsrv'] as $driver) {
    $manager->extend($driver, function ($config, $name) use ($app, $database) {
        if (($config['driver'] ?? '') !== 'sqlite' || ($config['database'] ?? '') !== $database || ! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])) throw new RuntimeException('Review database isolation rejected this connection.');
        return (new Illuminate\Database\Connectors\ConnectionFactory($app))->make($config, $name);
    });
}
Illuminate\Support\Facades\Http::preventStrayRequests();
return $app;
