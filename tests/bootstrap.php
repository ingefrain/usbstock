<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Laravel reads $_SERVER before getenv(); PHPUnit's force flag only updates
// getenv()/$_ENV. Mirror the forced values so inherited shell variables cannot win.
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'CACHE_STORE',
    'MAIL_MAILER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'APP_URL',
    'SUPPLIER_FTP_ENABLED', 'SUPPLIER_SYNC_ENABLED', 'SUPPLIER_SYNC_AUTO_APPLY',
    'SUPPLIER_FTP_HOST', 'SUPPLIER_FTP_USER', 'SUPPLIER_FTP_PASSWORD', 'SUPPLIER_FTP_PROTOCOL',
    'SUPPLIER_FTP_PORT', 'SUPPLIER_FTP_PATH', 'SUPPLIER_TRIAL_DATABASE', 'SUPPLIER_TRIAL_ALLOW_PLAINTEXT',
    'SUPPLIER_TELEGRAM_ENABLED', 'TELEGRAM_ENABLED', 'COMMERCE_NOTIFY_EMAIL',
    'CORPORATE_CONTACTS_ENABLED', 'CORPORATE_CONTACTS_KEY', 'CORPORATE_CONTACTS_RECORD_CLICKS'] as $name) {
    $value = getenv($name);
    if ($value === false) {
        throw new RuntimeException('Run tests with the project phpunit.xml configuration.');
    }
    $_SERVER[$name] = $_ENV[$name] = $value;
}

// A cached development configuration bypasses PHPUnit's environment overrides.
if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')
    || (getenv('APP_CONFIG_CACHE') && is_file(getenv('APP_CONFIG_CACHE')))) {
    throw new RuntimeException('Remove the application config cache before running isolated tests.');
}
