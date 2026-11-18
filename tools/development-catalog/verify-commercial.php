<?php

require __DIR__.'/local.php';

use App\Models\QuoteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$browser = json_decode(file_get_contents($runRoot.'/browser-review.json'), true, flags: JSON_THROW_ON_ERROR);
$before = json_decode(file_get_contents($runRoot.'/verification-before-browser.json'), true, flags: JSON_THROW_ON_ERROR);
$checks = 0;
$verify = function (bool $condition, string $label) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('Commercial review discrepancy: '.$label);
    }
    $checks++;
};
DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
$requests = QuoteRequest::with(['quote.items', 'quote.versions'])->get();
$first = $requests->first(fn ($request) => $request->customer['name'] === $browser['requests'][0]['name']);
$second = $requests->first(fn ($request) => $request->customer['name'] === $browser['requests'][1]['name']);
$verify($first !== null && $second !== null && $first->uuid !== $second->uuid, 'two separate requests');
$verify($first->status === 'converted' && $first->quote !== null, 'conversion');
$verify($first->seller_name === ' Asesor Octubre Prueba ' && $first->has_seller, 'literal seller');
$verify($first->seller_id !== null && $first->seller_confirmed_by !== null, 'explicit seller association');
$verify(array_column($first->items, 'quantity') === [50, 75], 'first quantities');
$verify(array_column($second->items, 'quantity') === [60] && ! $second->has_seller && blank($second->message), 'second request isolation');
$quote = $first->quote;
$verify($quote->versions->count() === 2 && $quote->status === 'issued', 'two issued versions');
$verify($quote->items->pluck('quantity')->all() === [50, 75], 'quote quantities');
$verify($quote->items->map(fn ($item) => $item->printing ?? '')->all() === ['LOGOTIPO DE PRUEBA OCTUBRE', ''], 'printing observations');
$versions = [];
foreach ($quote->versions->sortBy('version') as $version) {
    $snapshot = $version->snapshot;
    $verify($snapshot['calculation_version'] === 'legacy_v1' && $version->renderer_version === 'usbstock-2-letter', 'unchanged calculator/renderer');
    $verify($snapshot['source_request']['uuid'] === $first->uuid && $snapshot['source_request']['message'] === $first->message, 'historical origin');
    $verify($snapshot['seller_original_name'] === $first->seller_name && $snapshot['seller_id'] === $first->seller_id, 'historical attribution');
    $verify($snapshot['items'][0]['unit_minor'] === ($version->version === 1 ? 10000 : 11000), 'revision price snapshot');
    $bytes = Storage::disk('local')->get($version->path);
    $verify(hash_equals($version->sha256, hash('sha256', $bytes)) && strlen($bytes) === $version->size, 'private PDF hash');
    $verify(hash_equals($version->sha256, hash_file('sha256', $runRoot.'/october-fictitious-v'.$version->version.'.pdf')), 'downloaded PDF hash');
    foreach ($snapshot['items'] as $line) {
        $verify(hash_equals($line['photo']['sha256'], hash('sha256', Storage::disk('local')->get($line['photo']['path']))), 'frozen photo hash');
    }
    $versions[] = ['version' => $version->version, 'sha256' => $version->sha256, 'bytes' => $version->size, 'photos' => count($snapshot['items'])];
}
$fingerprint = hash('sha256', json_encode([DB::table('products')->orderBy('id')->get(), DB::table('product_variants')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
$verify(hash_equals($before['catalog_sha256'], $fingerprint), 'catalog unchanged by commercial flow');
$verify(DB::table('outbox_messages')->count() === 0, 'empty outbox');
$verify(DB::table('admin_audit_events')->where('action', 'quote_request.seller_assigned')->where('entity_id', $first->uuid)->whereNotNull('actor_id')->exists(), 'audited attribution');
DB::rollBack();
$report = ['passed' => true, 'checks' => $checks, 'requests' => [$first->uuid, $second->uuid], 'quote_uuid' => $quote->uuid,
    'folio' => $quote->folio, 'versions' => $versions, 'catalog_unchanged' => true, 'catalog_sha256' => $fingerprint, 'outbox' => 0];
writeDevelopmentReport('commercial-verification', $report);
echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
