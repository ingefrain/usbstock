<?php

require dirname(__DIR__).'/development-catalog/local.php';

use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Compare immutable PDFs and unrelated commercial configuration with the completed previous task.
$previous = json_decode(file_get_contents(storage_path('app/private/qa/operational-ux/after.json')), true, flags: JSON_THROW_ON_ERROR);
$report = ['database' => $identity->name, 'tables_unchanged' => [], 'pdfs_unchanged' => [], 'automation_disabled' => $environment];
foreach (['commercial_settings', 'commercial_clients', 'commercial_sellers', 'failed_jobs'] as $table) {
    $report['tables_unchanged'][$table] = hash_equals($previous['tables'][$table]['sha256'], hash('sha256', DB::table($table)->orderBy('id')->get()->toJson()));
    if (! $report['tables_unchanged'][$table]) {
        throw new RuntimeException('Protected table changed.');
    }
}
foreach (QuoteVersion::all() as $version) {
    $report['pdfs_unchanged'][$version->uuid] = hash_equals($previous['pdfs'][$version->uuid], hash_file('sha256', Storage::disk('local')->path($version->path)));
    if (! $report['pdfs_unchanged'][$version->uuid]) {
        throw new RuntimeException('Historical PDF changed.');
    }
}
if (config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('Corporate integration must remain off.');
}
$report['corporate_contacts_disabled'] = true;
file_put_contents(storage_path('app/private/taxonomy-20261118/historical-verification.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['protected_tables' => count($report['tables_unchanged']), 'historical_pdfs' => count($report['pdfs_unchanged']), 'unchanged' => true, 'automation_and_corporate_disabled' => true]).PHP_EOL;
