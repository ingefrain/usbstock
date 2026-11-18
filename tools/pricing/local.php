<?php

require dirname(__DIR__).'/development-catalog/local.php';

use App\Commerce\LegacyPriceScales;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$root = storage_path('app/private/taxonomy-20261118/corporate-pricing');
$backupPath = $root.'/backup-before.json';
$migration = 'database/migrations/2026_11_18_000002_add_commercial_price_scales.php';
if (config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('Corporate metrics must remain disabled.');
}
$mode = $argv[1] ?? 'verify';
if ($mode === 'backup') {
    if (is_file($backupPath)) {
        throw new RuntimeException('Do not overwrite the pre-change backup.');
    }
    $backup = ['database' => $identity->name, 'port' => (int) $identity->port, 'env_sha256' => hash_file('sha256', base_path('.env')), 'tables' => [], 'pdfs' => []];
    DB::statement('START TRANSACTION READ ONLY');
    try {
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if (in_array($table, ['sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }
            if (! preg_match('/\A[a-z_]+\z/', $table)) {
                throw new RuntimeException('Unexpected table name.');
            }
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
            $canonical = array_map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR), $rows);
            sort($canonical, SORT_STRING);
            $backup['tables'][$table] = ['columns' => DB::getSchemaBuilder()->getColumnListing($table), 'ddl' => array_values((array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`'))[1],
                'rows' => $rows, 'sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
        }
        foreach (QuoteVersion::all() as $version) {
            $backup['pdfs'][$version->uuid] = hash_file('sha256', Storage::disk('local')->path($version->path));
        }
    } finally {
        DB::statement('ROLLBACK');
    }
    file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    file_put_contents($root.'/backup.sha256', hash_file('sha256', $backupPath));
    echo json_encode(['backup_verified' => json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR) === $backup, 'tables' => count($backup['tables']), 'pdfs' => count($backup['pdfs'])]).PHP_EOL;
    exit;
}
if (! is_file($backupPath) || ! hash_equals(trim(file_get_contents($root.'/backup.sha256')), hash_file('sha256', $backupPath))) {
    throw new RuntimeException('Verified backup required.');
}
if ($mode === 'preview' || $mode === 'migrate') {
    Artisan::call('migrate', ['--path' => $migration, '--pretend' => $mode === 'preview', '--force' => true]);
    echo Artisan::output();
    exit;
}
if ($mode === 'import') {
    $reference = json_decode(file_get_contents($root.'/legacy-reference.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! hash_equals(hash_file('sha256', $root.'/legacy-reference.json'), hash_file('sha256', base_path('tests/Fixtures/Commerce/legacy-price-scales.json')))) {
        throw new RuntimeException('Reference differs from the regression fixture.');
    }
    echo json_encode(['created' => app(LegacyPriceScales::class)->import($reference, $actor->id)]).PHP_EOL;
    exit;
}
if ($mode !== 'verify') {
    throw new RuntimeException('Unknown mode.');
}
$before = json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR);
$report = ['unchanged_tables' => [], 'pdfs_unchanged' => 0, 'env_unchanged_after_authorized_activation' => hash_equals($before['env_sha256'], hash_file('sha256', base_path('.env'))), 'automations' => $environment['flags'],
    'corporate_enabled' => (bool) config('commerce.corporate_contacts.enabled'), 'corporate_metrics' => (bool) config('commerce.corporate_contacts.record_clicks')];
foreach ($before['tables'] as $table => $data) {
    if (in_array($table, ['migrations', 'admin_audit_events'], true)) {
        continue;
    }
    $rows = DB::table($table)->get($data['columns'])->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->all();
    sort($rows, SORT_STRING);
    $report['unchanged_tables'][$table] = hash_equals($data['sha256'], hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)));
    if (! $report['unchanged_tables'][$table]) {
        throw new RuntimeException('Pre-existing business rows changed: '.$table);
    }
}
foreach (QuoteVersion::all() as $version) {
    if (! isset($before['pdfs'][$version->uuid]) || ! hash_equals($before['pdfs'][$version->uuid], hash_file('sha256', Storage::disk('local')->path($version->path)))) {
        throw new RuntimeException('Historical PDF changed.');
    }
    $report['pdfs_unchanged']++;
}
if (! $report['env_unchanged_after_authorized_activation'] || $report['corporate_metrics']) {
    throw new RuntimeException('Unexpected configuration change.');
}
file_put_contents($root.'/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['unchanged_tables' => count($report['unchanged_tables']), 'pdfs_unchanged' => $report['pdfs_unchanged'], 'configuration_verified' => true]).PHP_EOL;
