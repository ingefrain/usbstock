<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__.'/bootstrap.php';

use App\Models\QuoteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Queries are read-only. The bootstrap only permits the exact isolated SQLite file.
DB::statement('PRAGMA query_only = ON');
$checks = 0;
$verify = function (bool $ok, string $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException('Persistence verification failed: '.$label);
    }
    $checks++;
};
$requests = QuoteRequest::with(['quote.items', 'quote.versions', 'seller'])->get()
    ->filter(fn ($request) => str_starts_with($request->customer['name'], 'QA Edge '));
$verify($requests->count() >= 3, 'three viewport submissions exist');
$report = ['checked_at' => gmdate('c'), 'isolated_database' => true, 'requests' => []];
foreach ($requests as $request) {
    $quote = $request->quote;
    $verify($request->status === 'converted' && $quote !== null, 'request converted');
    $verify($request->seller_name === ' Josè Luis ' && $request->has_seller, 'literal seller');
    $verify($request->seller_id !== null && $request->seller_confirmed_by !== null && $request->seller_confirmed_at !== null, 'explicit association');
    $verify($quote->seller_id === $request->seller_id && $quote->seller_original_name === $request->seller_name, 'quote attribution');
    $verify($quote->sourceRequest->id === $request->id && $quote->commercial_client_id !== null, 'origin and client');
    $client = DB::table('commercial_clients')->find($quote->commercial_client_id);
    $verify($client->name === $request->customer['name'] && $client->country === 'México' && $client->company === 'Empresa QA', 'client contact');
    $verify($quote->customer['email'] === $request->customer['email'] && $quote->customer['phone'] === $request->customer['phone'], 'quote contact');
    $verify(array_column($request->items, 'quantity') === [80, 75], 'submitted quantities');
    $verify($quote->items->pluck('quantity')->all() === [80, 75], 'saved quantities');
    $verify($quote->items->pluck('printing')->all() === ['Logotipo · prueba Edge', 'Logotipo · prueba Edge'], 'printing');
    $verify($quote->status === 'draft' && $quote->revision >= 4 && $quote->versions->count() === 1, 'revision keeps issued version');
    $version = $quote->versions->sole();
    $snapshot = $version->snapshot;
    $verify($snapshot['source_request']['uuid'] === $request->uuid && $snapshot['source_request']['message'] === $request->message, 'snapshot origin and observations');
    $verify($snapshot['seller_id'] === $quote->seller_id && $snapshot['seller_original_name'] === $request->seller_name && $snapshot['client_id'] === $quote->commercial_client_id, 'snapshot relationships');
    $verify($snapshot['items'][0]['unit_minor'] === 2000 && $snapshot['items'][1]['unit_minor'] === 2500 && $snapshot['items'][0]['printing_minor'] === 200, 'saved prices');
    $bytes = Storage::disk('local')->get($version->path);
    $verify(is_string($bytes) && hash('sha256', $bytes) === $version->sha256 && strlen($bytes) === $version->size, 'historical PDF bytes');
    $verify($version->renderer_version === 'usbstock-2-letter', 'Letter renderer');
    foreach ($snapshot['items'] as $line) {
        $photo = $line['photo'];
        $verify(hash('sha256', Storage::disk('local')->get($photo['path'])) === $photo['sha256'], 'historical photo bytes');
    }
    $verify(DB::table('admin_audit_events')->where('action', 'quote_request.seller_assigned')->where('entity_id', $request->uuid)->whereNotNull('actor_id')->exists(), 'audited association');
    $report['requests'][] = ['request' => $request->uuid, 'quote' => $quote->uuid, 'revision' => $quote->revision, 'version' => $version->version, 'pdf_sha256' => $version->sha256];
}
$verify(DB::table('products')->count() === 362 && DB::table('product_variants')->count() === 818, 'reference catalog');
$verify(! DB::table('product_variants')->where('stock', '!=', 250)->exists(), 'synthetic stock unchanged');
$verify(DB::table('outbox_messages')->count() === 0, 'no commercial outbox');
foreach (['commerce.sync.ftp_enabled', 'commerce.sync.enabled', 'commerce.sync.auto_apply', 'commerce.telegram_enabled', 'services.telegram.enabled', 'notifications.telegram.enabled'] as $key) {
    $verify(! config($key), 'disabled automation');
}
$verify(blank(config('commerce.notify_email')) && config('mail.default') === 'array', 'no external email');
$report['checks'] = $checks;
$report['passed'] = true;
file_put_contents($reviewRoot.'/persistence-report.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode(['requests' => $requests->count(), 'checks' => $checks, 'passed' => true, 'inventory_unchanged' => true, 'outbox' => 0]).PHP_EOL;
