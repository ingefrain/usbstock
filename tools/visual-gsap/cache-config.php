<?php

use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$standard = in_array('--verify-standard', $argv, true);
if ($standard) {
    $project = dirname(__DIR__, 2);
    $reviewRoot = $project.'/storage/app/private/qa/visual-gsap';
    require $project.'/vendor/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    if (! $app->configurationIsCached()
        || str_replace('\\', '/', $app->getCachedConfigPath()) !== str_replace('\\', '/', $reviewRoot.'/web-config.php')
        || str_replace('\\', '/', $app->storagePath()) !== str_replace('\\', '/', $reviewRoot.'/runtime')) {
        throw new RuntimeException('The standard Laravel bootstrap did not select the private cache and runtime.');
    }
    $app->make(Kernel::class)->bootstrap();
} else {
    require __DIR__.'/bootstrap.php';
}

// The standard Laravel server can consume this private configuration directly.
// Configuration caching makes LoadEnvironmentVariables skip dotenv entirely.
$config = config()->all();
$runtime = $reviewRoot.'/runtime';
if (array_keys($config['database']['connections']) !== ['sqlite']
    || $config['database']['default'] !== 'sqlite'
    || $config['database']['connections']['sqlite']['database'] !== $reviewRoot.'/review.sqlite'
    || ! empty($config['database']['connections']['sqlite']['url'])
    || $config['session']['driver'] !== 'file'
    || $config['session']['cookie'] !== 'usbstock_visual_gsap_review'
    || str_replace('\\', '/', $config['session']['files']) !== str_replace('\\', '/', $runtime.'/framework/sessions')
    || str_replace('\\', '/', $config['view']['compiled']) !== str_replace('\\', '/', $runtime.'/framework/views')
    || str_replace('\\', '/', $config['filesystems']['disks']['local']['root']) !== str_replace('\\', '/', $runtime.'/app/private')
    || $config['commerce']['sync']['enabled'] || $config['commerce']['sync']['ftp_enabled'] || $config['commerce']['sync']['auto_apply']
    || $config['commerce']['telegram_enabled'] || $config['commerce']['notify_email']
    || $config['commerce']['corporate_contacts']['enabled'] || $config['commerce']['corporate_contacts']['record_clicks']
    || $config['services']['telegram']['enabled'] || $config['mail']['default'] !== 'array') {
    throw new RuntimeException('Refusing to cache a configuration outside the isolated preview contract.');
}
$instance = json_decode(file_get_contents($reviewRoot.'/instance.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($instance['key'], $config['app']['key']) || empty($config['commerce']['visual_snapshot'])) {
    throw new RuntimeException('The private preview key or snapshot flag is invalid.');
}
if ($standard) {
    echo "Standard Laravel bootstrap verified: private cached configuration loaded without dotenv.\n";
    exit;
}
$cache = $reviewRoot.'/web-config.php';
if (file_put_contents($cache, '<?php return '.var_export($config, true).';'.PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Could not write the private preview configuration.');
}
$loaded = require $cache;
if ($loaded !== $config) {
    throw new RuntimeException('Private preview configuration did not round-trip correctly.');
}
echo "Private Laravel configuration validated: isolated SQLite, sessions, storage, key and disabled integrations.\n";
