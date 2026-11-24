<?php

use Illuminate\Contracts\Console\Kernel;

$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.'.config('database.default'));
$flags = ['ftp' => config('commerce.sync.ftp_enabled'), 'scheduler' => config('commerce.sync.enabled'), 'auto_apply' => config('commerce.sync.auto_apply'), 'commercial_telegram' => config('commerce.telegram_enabled'), 'telegram' => config('services.telegram.enabled'), 'mail_recipient_present' => filled(config('commerce.notify_email'))];
$correct = ($connection['database'] ?? '') === 'usbstock_dev' && ($connection['host'] ?? '') === '127.0.0.1' && (string) ($connection['port'] ?? '') === '3307';
echo json_encode(['expected_local_database' => $correct, 'flags' => $flags, 'network_used' => false], JSON_PRETTY_PRINT)."\n";
if (! $correct || array_filter($flags)) {
    exit(1);
}
