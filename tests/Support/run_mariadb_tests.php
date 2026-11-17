<?php

use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$base = dirname(__DIR__, 2);
$file = $base.'/.env.testing.mariadb';
if (! is_file($file)) {
    fwrite(STDERR, "Missing private .env.testing.mariadb configuration.\n");
    exit(1);
}
$values = Dotenv\Dotenv::parse(file_get_contents($file));
$connection = [
    'driver' => 'mysql',
    'host' => $values['OUTBOX_MARIADB_HOST'] ?? '',
    'port' => $values['OUTBOX_MARIADB_PORT'] ?? '',
    'database' => $values['OUTBOX_MARIADB_DATABASE'] ?? '',
    'username' => $values['OUTBOX_MARIADB_USERNAME'] ?? '',
];
TestDatabaseSafety::assertConnection($connection, '1');
if (empty($values['OUTBOX_MARIADB_PASSWORD'])) {
    throw new RuntimeException('Missing dedicated test runner password.');
}
$environment = array_intersect_key($values, array_flip([
    'OUTBOX_MARIADB_HOST', 'OUTBOX_MARIADB_PORT', 'OUTBOX_MARIADB_DATABASE',
    'OUTBOX_MARIADB_USERNAME', 'OUTBOX_MARIADB_PASSWORD',
]));
$environment += [
    'OUTBOX_MARIADB_TEST' => '1', 'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
];
$report = tempnam(sys_get_temp_dir(), 'usbstock-mariadb-');
if ($report === false) {
    throw new RuntimeException('Cannot create the test completion report.');
}
try {
    $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', 'tests/Integration/MariaDbOutboxIntegrationTest.php', '--log-junit', $report], $base, $environment);
    $process->setTimeout(600);
    $exitCode = $process->run(function (string $type, string $buffer): void {
        echo $buffer;
    });
    $xml = filesize($report) > 0 ? simplexml_load_file($report) : false;
    if ($exitCode === 0 && ($xml === false || (int) $xml->testsuite['tests'] !== 9
        || (int) $xml->testsuite['skipped'] !== 0 || (int) $xml->testsuite['assertions'] === 0)) {
        fwrite(STDERR, "MariaDB tests did not produce a complete nine-test report.\n");
        $exitCode = 1;
    }
} finally {
    unlink($report);
}
exit($exitCode);
