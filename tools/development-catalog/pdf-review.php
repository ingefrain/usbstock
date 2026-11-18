<?php

require __DIR__.'/local.php';

use App\Commerce\QuoteDesigns;
use App\Commerce\QuotePhotos;
use App\Models\Quote;
use Illuminate\Support\Facades\Storage;

$concept = $argv[1] ?? '';
if (! isset(QuoteDesigns::LAB_CONCEPTS[$concept])) {
    throw new RuntimeException('Choose b1, b2 or b3; this does not activate an official template.');
}
$report = json_decode(file_get_contents($runRoot.'/commercial-verification.json'), true, flags: JSON_THROW_ON_ERROR);
$quote = Quote::where('uuid', $report['quote_uuid'])->sole();
$version = $quote->versions()->where('version', 2)->sole();
$snapshot = $version->snapshot;
if (! str_starts_with($snapshot['customer']['name'], 'PRUEBA LOCAL OCTUBRE A ')) {
    throw new RuntimeException('Only the authorized fictitious development quotation may be rendered.');
}
foreach ($snapshot['items'] as &$line) {
    $photo = $line['photo'];
    $bytes = Storage::disk('local')->get($photo['path']);
    if (! hash_equals($photo['sha256'], hash('sha256', $bytes))) {
        throw new RuntimeException('Frozen photo checksum differs.');
    }
    $line['photo_bytes'] = app(QuotePhotos::class)->square($bytes);
}
unset($line);
$bytes = app(QuoteDesigns::class)->render($concept, $snapshot);
$filename = 'october-proposal-'.$concept.'.pdf';
file_put_contents($runRoot.'/'.$filename, $bytes);
writeDevelopmentReport('proposal-'.$concept, ['proposal_only' => true, 'official_unchanged' => true,
    'source_quote' => $quote->uuid, 'source_version' => 2, 'filename' => $filename, 'bytes' => strlen($bytes),
    'sha256' => hash('sha256', $bytes), 'photos' => count($snapshot['items'])]);
echo strtoupper($concept).' reference PDF created; no historical or official document changed.'.PHP_EOL;
