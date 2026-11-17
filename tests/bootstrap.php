<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Laravel reads $_SERVER before getenv(); PHPUnit's force flag only updates
// getenv()/$_ENV. Mirror the forced values so inherited shell variables cannot win.
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'CACHE_STORE',
    'MAIL_MAILER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'APP_URL'] as $name) {
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
