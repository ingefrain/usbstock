<?php

require dirname(__DIR__).'/development-catalog/local.php';

use App\Commerce\CorporateDirectory;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

$root = storage_path('app/private/taxonomy-20261118/corporate-pricing');
if (! is_dir($root)) {
    mkdir($root, 0700, true);
}
$file = $root.'/corporate-check.json';
if (is_file($file) || ! config('commerce.corporate_contacts.enabled') || config('commerce.corporate_contacts.record_clicks')) {
    throw new RuntimeException('Requires authorized local listing, metrics disabled, and no previous attempt.');
}
$report = ['attempts' => 0, 'http_status' => null, 'json_content_type' => false, 'connection_failed' => false, 'validated' => false, 'metrics_enabled' => false];
// Persist the attempt marker before networking. Never log request URLs or raw exceptions.
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
Http::allowStrayRequests(['https://grupotalento.com.mx/includes/vendedores.php?*']);
Event::listen(RequestSending::class, function ($event) use (&$report): void {
    if (++$report['attempts'] !== 1 || ! str_starts_with($event->request->url(), 'https://grupotalento.com.mx/includes/vendedores.php?')
        || $event->request['data'] !== 1 || isset($event->request['save'])) {
        throw new RuntimeException('Only one authorized directory read is allowed.');
    }
});
Event::listen(ResponseReceived::class, function ($event) use (&$report): void {
    $report['http_status'] = $event->response->status();
    $report['json_content_type'] = str_contains(strtolower($event->response->header('Content-Type')), 'application/json');
});
Event::listen(ConnectionFailed::class, function () use (&$report): void {
    $report['connection_failed'] = true;
});
try {
    $sellers = app(CorporateDirectory::class)->sellers();
    $json = json_encode(['sellers' => $sellers], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (str_contains($json, (string) config('commerce.corporate_contacts.key'))) {
        throw new RuntimeException('Private configuration appeared in the response.');
    }
    file_put_contents($root.'/corporate-validated.json', $json);
    $report += ['sellers' => count($sellers), 'online' => count(array_filter($sellers, fn ($s) => $s['available'])), 'photos_allowed' => count(array_filter($sellers, fn ($s) => $s['photo'] !== null)), 'safe_links' => count(array_filter($sellers, fn ($s) => $s['url'] !== null))];
    $report['validated'] = true;
} catch (Throwable) {
    $report['diagnostic'] = $report['connection_failed'] ? 'transport_failed' : 'response_not_accepted_by_existing_validation';
}
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report).PHP_EOL;
