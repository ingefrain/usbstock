<?php

// Read-only guard and fingerprints. Never prints configuration secrets or business rows.
require dirname(__DIR__).'/development-catalog/local.php';

use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('Corporate contacts must remain disabled.');
}
$root = storage_path('app/private/taxonomy-20261118/corporate-restoration');
if (! is_dir($root)) {
    mkdir($root, 0700, true);
}
$mode = $argv[1] ?? 'after';
if (! in_array($mode, ['before', 'after'], true)) {
    throw new RuntimeException('Expected before or after.');
}
$report = ['database' => $identity->name, 'port' => (int) $identity->port, 'automation' => $environment['flags'], 'corporate_contacts_enabled' => false, 'corporate_contacts_record_clicks' => false, 'tables' => [], 'pdfs' => []];
DB::statement('START TRANSACTION READ ONLY');
try {
    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];
        if (in_array($table, ['sessions', 'cache', 'cache_locks'], true)) {
            continue;
        }
        $rows = DB::table($table)->get()->map(fn ($record) => json_encode($record, JSON_THROW_ON_ERROR))->all();
        sort($rows, SORT_STRING);
        $report['tables'][$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    foreach (QuoteVersion::all() as $version) {
        $path = Storage::disk('local')->path($version->path);
        if (! is_file($path)) {
            throw new RuntimeException('Historical PDF is missing.');
        }
        $report['pdfs'][$version->uuid] = hash_file('sha256', $path);
    }
} finally {
    DB::statement('ROLLBACK');
}
$report['env_sha256'] = hash_file('sha256', base_path('.env'));
$file = $root.'/'.$mode.'.json';
if ($mode === 'before' && is_file($file)) {
    throw new RuntimeException('Baseline already exists; do not overwrite it.');
}
if ($mode === 'after') {
    $before = json_decode(file_get_contents($root.'/before.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($before !== $report) {
        throw new RuntimeException('Protected data or local configuration changed.');
    }
}
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['mode' => $mode, 'database' => $report['database'], 'tables' => count($report['tables']), 'pdfs' => count($report['pdfs']), 'all_automations_disabled' => true, 'corporate_disabled' => true, 'verified' => true]).PHP_EOL;
