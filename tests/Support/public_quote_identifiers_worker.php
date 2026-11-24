<?php

use App\Commerce\Api\PublicQuoteIdentifiers;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// This worker only accepts a fresh, marked, randomly named directory directly under the OS temp root.
$directory = realpath((string) ($argv[1] ?? ''));
$temporary = realpath(sys_get_temp_dir());
$mode = $argv[2] ?? '';
if ($directory === false || $temporary === false || dirname($directory) !== $temporary
    || ! preg_match('/\Ausbstock-identifiers-[a-f0-9]{32}\z/D', basename($directory))
    || is_link($directory) || ! is_file($directory.'/test-only')
    || file_get_contents($directory.'/test-only') !== 'isolated-public-identifiers-test'
    || ! in_array($mode, ['initialize', 'assign', 'inspect'], true)) {
    fwrite(STDERR, 'Isolated worker destination rejected.');
    exit(2);
}
$database = $directory.'/identifiers.sqlite';
if ($mode === 'initialize') {
    $handle = @fopen($database, 'x');
    if (! is_resource($handle)) {
        fwrite(STDERR, 'Initialization requires a new database file.');
        exit(2);
    }
    fclose($handle);
} elseif (! is_file($database) || is_link($database)) {
    fwrite(STDERR, 'Isolated database missing or invalid.');
    exit(2);
}

try {
    file_put_contents($directory.'/stage-'.$mode, 'bootstrap');
    // Override any inherited development values before Laravel can read configuration.
    foreach ([
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'SUPPLIER_FTP_ENABLED' => 'false', 'SUPPLIER_SYNC_ENABLED' => 'false', 'SUPPLIER_SYNC_AUTO_APPLY' => 'false',
        'SUPPLIER_TELEGRAM_ENABLED' => 'false', 'TELEGRAM_ENABLED' => 'false', 'COMMERCE_NOTIFY_EMAIL' => '',
        'CORPORATE_CONTACTS_ENABLED' => 'false', 'CORPORATE_CONTACTS_RECORD_CLICKS' => 'false', 'USBSTOCK_API_ENABLED' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_SERVER[$key] = $_ENV[$key] = $value;
    }
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    file_put_contents($directory.'/stage-'.$mode, 'configured');
    if (! $app->environment('testing')) {
        throw new RuntimeException('Testing environment required.');
    }
    config(['database.default' => 'identifier_concurrency', 'database.connections.identifier_concurrency' => [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
    ]]);
    DB::purge('identifier_concurrency');

    if ($mode === 'initialize') {
        file_put_contents($directory.'/stage-'.$mode, 'migrations');
        // Create the real schema in one SQLite transaction. Per-statement file flushes are
        // prohibitively slow on some Windows temporary volumes; no production setting changes.
        $migrator = $app->make('migrator');
        $migrator->setConnection('identifier_concurrency');
        DB::transaction(function () use ($migrator): void {
            $migrator->getRepository()->createRepository();
            $migrator->run([database_path('migrations')]);
        });
        file_put_contents($directory.'/stage-'.$mode, 'fixtures');
        $quotes = [];
        foreach (range(1, 3) as $number) {
            $quote = Quote::create(['status' => 'issued', 'customer' => ['name' => 'Synthetic concurrency '.$number, 'email' => 'concurrency@example.invalid'], 'seller' => '', 'totals' => []]);
            $quote->versions()->create(['version' => 1, 'request_key' => (string) Str::uuid(), 'snapshot' => [], 'path' => 'synthetic.pdf',
                'sha256' => str_repeat('0', 64), 'size' => 0, 'renderer_version' => 'identifier-concurrency', 'created_at' => now()]);
            $quotes[] = ['id' => $quote->id, 'uuid' => $quote->uuid];
        }
        echo json_encode($quotes, JSON_THROW_ON_ERROR);
    } elseif ($mode === 'assign') {
        $quoteId = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $group = $argv[4] ?? '';
        if ($quoteId === false || ! in_array($group, ['distinct', 'same'], true)) {
            throw new RuntimeException('Worker request rejected.');
        }
        touch($directory.'/ready-'.$group.'-'.getmypid());
        $deadline = microtime(true) + 10;
        while (! is_file($directory.'/gate-'.$group)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrency gate expired.');
            }
            usleep(10000);
        }
        // Hold the acquired SQLite reservation briefly so the independent workers overlap.
        $held = false;
        DB::listen(function (QueryExecuted $query) use (&$held): void {
            if (! $held && str_starts_with($query->sql, 'update "commerce_locks"')) {
                $held = true;
                usleep(150000);
            }
        });
        $quote = Quote::findOrFail($quoteId);
        $first = app(PublicQuoteIdentifiers::class)->assign($quote);
        $retry = app(PublicQuoteIdentifiers::class)->assign($quote);
        echo json_encode(['id' => $first->public_id, 'retry' => $retry->public_id], JSON_THROW_ON_ERROR);
    } else {
        echo json_encode([
            'mappings' => QuotePublicIdentifier::orderBy('public_id')->get(['public_id', 'quote_id', 'origin', 'source_id'])->toArray(),
            'cursor' => (int) DB::table('commerce_locks')->value('public_quote_sequence'),
            'folio_sequence' => (int) DB::table('commerce_locks')->value('quote_sequence'),
        ], JSON_THROW_ON_ERROR);
    }
    DB::disconnect('identifier_concurrency');
    file_put_contents($directory.'/stage-'.$mode, 'done');
} catch (Throwable $error) {
    // Test failures disclose only exception type, never inherited configuration or query contents.
    fwrite(STDERR, get_class($error));
    exit(1);
}
