<?php

namespace Tests\Feature;

use App\Commerce\Api\PublicQuoteIdentifiers;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use App\Models\QuoteVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class QuoteReadApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->token = bin2hex(random_bytes(32));
        $encryptionKey = random_bytes(32);
        config([
            'app.key' => 'base64:'.base64_encode($encryptionKey),
            'quote_api.enabled' => true, 'quote_api.bearer_token' => $this->token,
            'quote_api.consumer' => 'grupotalento', 'quote_api.environment' => 'local',
            'quote_api.allowed_quote_uuids' => [], 'quote_api.pdf_enabled' => true,
            'quote_api.base_url' => 'http://127.0.0.1:8001',
            'quote_api.read_rate_limit' => 60, 'quote_api.pdf_rate_limit' => 30, 'quote_api.ip_rate_limit' => 120,
        ]);
        Crypt::swap(new Encrypter($encryptionKey, 'AES-256-CBC'));
    }

    public function test_success_matches_the_anonymized_contract_with_native_nullable_fields(): void
    {
        [, , $id] = $this->fixture();
        $response = $this->read($id)->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $data = $response->json('data');
        $reference = json_decode(file_get_contents(base_path('tests/Fixtures/Api/quote-v1-anonymized.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContract($reference, $response->json());
        $this->assertSame($id, $data['id']);
        $this->assertSame('Contacto ficticio Á & Ω', $data['customer']['name']);
        $this->assertSame('2026-11-23T12:00:00-06:00', $data['dates']['elaborated_at']);
        $this->assertSame($data['dates']['elaborated_at'], $data['amounts_recorded_at']);
        foreach ($data['dates'] as $date) {
            $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-06:00\z/', $date);
        }
        $this->assertNull($data['items'][0]['id']);
        $this->assertNull($data['items'][0]['variant_sku']);
        $this->assertNull($data['customer']['secondary_phone']);
        $this->assertSame(['currency' => 'MXN', 'sale_amount' => 1099.0, 'shipping' => 50.01, 'tax' => 176.02, 'total' => 1325.03], $data['amounts']);
        $this->assertTrue($data['sale_amount_available']);
        $this->assertNull($data['sale_amount_unavailable_reason']);
        $this->assertSame('last_confirmed_quote_send', $data['amounts_basis']);
        $this->assertSame('Comentario de la emisión', $data['comments']);
        $this->assertFalse($data['archived']);
        $this->assertStringContainsString('Á &amp; Ω', $data['items_html']);
        $this->assertStringContainsString('Á & Ω', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->headers->get('X-Request-ID'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertNotSame($response->headers->get('X-Request-ID'), $this->read($id)->headers->get('X-Request-ID'));
    }

    public function test_bearer_is_required_and_failures_do_not_use_sessions_or_expose_configuration(): void
    {
        [, , $id] = $this->fixture();
        foreach ([null, '', 'Basic abc', 'Bearer', 'Bearer invalid credential', 'Bearer '.bin2hex(random_bytes(32))] as $header) {
            $response = $this->getJson($this->url($id), $header === null ? [] : ['Authorization' => $header]);
            $this->assertApiError($response, 401, 'UNAUTHORIZED', 'Autenticación requerida o inválida');
            $response->assertHeader('WWW-Authenticate', 'Bearer realm="Publimarca API"');
            $this->assertStringNotContainsString($this->token, $response->getContent());
        }
        foreach (['quote_api.bearer_token' => null, 'quote_api.enabled' => false] as $key => $value) {
            $previous = config($key);
            config([$key => $value]);
            $this->assertApiError($this->read($id), 401, 'UNAUTHORIZED', 'Autenticación requerida o inválida');
            config([$key => $previous]);
        }
        $this->getJson($this->url($id), ['Authorization' => "bEaReR\t".$this->token." \t"])->assertOk();
    }

    public function test_an_issued_quote_outside_the_grant_is_indistinguishable_from_an_unknown_id(): void
    {
        [, , $id] = $this->fixture(grant: false);
        $denied = $this->read($id);
        $missing = $this->read($id + 100);
        $this->assertApiError($denied, 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        $this->assertSame($missing->getStatusCode(), $denied->getStatusCode());
        $this->assertSame($missing->getContent(), $denied->getContent());
    }

    public function test_only_real_get_requests_are_allowed_including_method_override_attempts(): void
    {
        [, , $id] = $this->fixture();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method) {
            $response = $this->call($method, $this->url($id), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
            $response->assertStatus(405)->assertHeader('Allow', 'GET');
            if ($method === 'HEAD') {
                $this->assertSame('', $response->getContent());
            } else {
                $this->assertApiError($response, 405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET');
            }
        }
        $response = $this->postJson($this->url($id), [], ['Authorization' => 'Bearer '.$this->token, 'X-HTTP-Method-Override' => 'GET']);
        $this->assertApiError($response, 405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET');
        $response = $this->post($this->url($id), ['_method' => 'GET'], ['Authorization' => 'Bearer '.$this->token]);
        $this->assertApiError($response, 405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET');
    }

    public function test_id_grammar_uses_the_original_path_and_preserves_supported_encodings(): void
    {
        [, , $id] = $this->fixture();
        foreach (['0', '-1', '01', '1.0', '10000000000', '1%20OR%201%3D1', $id.'/extra', $id.'//', $id.'%2F%2F', ''] as $invalid) {
            // An absolute URL plus query bypasses the test URL builder's final-slash normalization.
            $response = $this->read($invalid.'?_transport=1');
            $this->assertSame(400, $response->getStatusCode(), 'Invalid path: '.$invalid);
            $this->assertApiError($response, 400, 'INVALID_ID', 'ID de cotización inválido');
        }
        $this->read($id.'/?_transport=1')->assertOk();
        $encoded = implode('', array_map(fn ($digit) => sprintf('%%%02X', ord($digit)), str_split((string) $id)));
        $this->read($encoded)->assertOk()->assertJsonPath('data.id', $id);
        $this->read($id.'?id=1&filter=ignored')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertApiError($this->getJson('/api/v1/cotizaciones', ['Authorization' => 'Bearer '.$this->token]), 404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada');
        $this->assertApiError($this->getJson('/api/v1/desconocido', ['Authorization' => 'Bearer '.$this->token]), 404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada');
    }

    public function test_drafts_and_revisions_are_unavailable_until_a_new_version_is_issued(): void
    {
        [$draft, , $draftId] = $this->fixture(status: 'draft', withVersion: false);
        $this->assertApiError($this->read($draftId), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        [$quote, , $id] = $this->fixture();
        $quote->update(['status' => 'draft', 'customer' => ['name' => 'Cambió el borrador'], 'totals' => ['total' => 1]]);
        $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        $snapshot = $this->snapshot(2);
        $snapshot['source_request']['message'] = 'Segunda emisión';
        $this->version($quote, 2, $snapshot);
        $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        $quote->update(['status' => 'issued']);
        $this->read($id)->assertOk()->assertJsonPath('data.comments', 'Segunda emisión')->assertJsonPath('data.customer.name', 'Contacto ficticio Á & Ω');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_version_order_uses_the_persisted_ordinal_and_never_falls_back_from_invalid_latest(): void
    {
        [$quote, , $id] = $this->fixture();
        $snapshot = $this->snapshot(2);
        $snapshot['issued_at'] = '2020-01-15T18:00:00+00:00';
        $snapshot['source_request']['message'] = 'Ordinal dos, fecha anterior';
        $version = $this->version($quote, 2, $snapshot, '2020-01-15 18:00:00');
        $response = $this->read($id)->assertOk()->assertJsonPath('data.comments', 'Ordinal dos, fecha anterior');
        $this->assertSame('2020-01-15T12:00:00-06:00', $response->json('data.amounts_recorded_at'));
        $download = $this->get($response->json('data.pdf.url'))->assertOk();
        $this->assertSame(Storage::disk('local')->get($version->path), $download->getContent());
        $invalid = $this->snapshot(3);
        $invalid['totals']['tax'] = null;
        $this->version($quote, 3, $invalid);
        $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
    }

    public function test_missing_or_corrupt_emitted_amounts_are_not_replaced_with_zero(): void
    {
        foreach ([null, '17602', -1] as $invalid) {
            $snapshot = $this->snapshot();
            $snapshot['totals']['tax'] = $invalid;
            [, , $id] = $this->fixture($snapshot);
            $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        }
        $snapshot = $this->snapshot();
        unset($snapshot['totals']);
        [, , $id] = $this->fixture($snapshot);
        $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        [, , $id] = $this->fixture(withVersion: false);
        $this->assertApiError($this->read($id), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
    }

    public function test_public_pdf_reference_downloads_its_exact_version_and_errors_remain_json(): void
    {
        [, $version, $id] = $this->fixture();
        $response = $this->read($id)->assertOk();
        $url = $response->json('data.pdf.url');
        $this->assertTrue($response->json('data.pdf.available'));
        $this->assertSame('latest_generated_quote_pdf', $response->json('data.pdf.basis'));
        $this->assertStringNotContainsString($this->token, $url);
        $this->assertStringNotContainsString($version->path, $url);
        $download = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(Storage::disk('local')->get($version->path), $download->getContent());
        $head = $this->call('HEAD', $url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('', $head->getContent());
        $this->assertApiError($this->get('/api/v1/documentos/invalid'), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        Storage::disk('local')->delete($version->path);
        $this->read($id)->assertOk()->assertJsonPath('data.pdf.available', false)->assertJsonPath('data.pdf.url', null);
        $this->assertApiError($this->get($url), 404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
    }

    public function test_invalid_capabilities_do_not_consume_the_authorized_pdf_allowance(): void
    {
        [, , $id] = $this->fixture();
        $url = $this->read($id)->json('data.pdf.url');
        config(['quote_api.pdf_rate_limit' => 1]);
        for ($index = 0; $index < 3; $index++) {
            $this->get('/api/v1/documentos/invalid-'.$index)->assertNotFound();
        }
        $this->assertSame(0, RateLimiter::attempts('quote-api:pdf:grupotalento'));
        $this->get($url)->assertOk();
        $this->assertApiError($this->get($url), 429, 'RATE_LIMITED', 'Demasiadas solicitudes');
    }

    public function test_read_and_ip_limits_have_independent_buckets_and_retry_after(): void
    {
        [, , $id] = $this->fixture();
        config(['quote_api.read_rate_limit' => 1]);
        $this->read($id)->assertOk();
        $limited = $this->read($id);
        $this->assertApiError($limited, 429, 'RATE_LIMITED', 'Demasiadas solicitudes');
        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        RateLimiter::clear('quote-api:read:grupotalento');
        RateLimiter::clear('quote-api:ip:'.hash('sha256', '127.0.0.1'));
        config(['quote_api.ip_rate_limit' => 1, 'quote_api.read_rate_limit' => 60]);
        $this->getJson($this->url($id))->assertUnauthorized();
        $this->assertApiError($this->read($id), 429, 'RATE_LIMITED', 'Demasiadas solicitudes');
    }

    public function test_technical_logs_exclude_credentials_document_urls_and_commercial_payloads(): void
    {
        [, , $id] = $this->fixture();
        $entries = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->times(3)->andReturnUsing(function ($message, $context) use (&$entries): void {
            $entries[] = ['message' => $message, 'context' => $context];
        });
        Log::shouldReceive('channel')->with('quote_api')->times(3)->andReturn($logger);
        $response = $this->read($id)->assertOk();
        $url = $response->json('data.pdf.url');
        $this->get($url)->assertOk();
        $this->getJson($this->url($id), ['Authorization' => 'Bearer '.bin2hex(random_bytes(32))])->assertUnauthorized();
        $this->assertCount(3, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('quote_api.read', $entry['message']);
            $this->assertSame(['request_id', 'operation', 'consumer', 'public_id', 'http_status', 'duration_ms', 'exception_type'], array_keys($entry['context']));
        }
        $logged = json_encode($entries, JSON_THROW_ON_ERROR);
        foreach ([$this->token, $url, basename($url), 'provider_private_marker', 'margin_private_marker', 'fixture@example.invalid', 'Contacto ficticio', '1099.0'] as $private) {
            $this->assertStringNotContainsString($private, $logged);
        }
        $this->assertSame([200, 200, 401], array_column(array_column($entries, 'context'), 'http_status'));
    }

    public function test_reads_use_persisted_data_without_database_or_document_writes(): void
    {
        [$quote, $version, $id] = $this->fixture();
        $before = $quote->fresh()->getAttributes();
        $versionBefore = $version->fresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $pdfBytes = Storage::disk('local')->get($version->path);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->read($id)->assertOk();
        $this->get($response->json('data.pdf.url'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/product_variants|commercial_clients|commercial_settings|quote_items/i', $query['query']);
        }
        $this->assertSame($before, $quote->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($pdfBytes, Storage::disk('local')->get($version->path));
        $this->assertDatabaseCount('quote_versions', 1);
        $this->assertDatabaseCount('quote_public_identifiers', 1);
        // Snapshot totals intentionally differ from current unit prices × quantity and tax rules.
        $this->assertSame(1099.0, $response->json('data.amounts.sale_amount'));
        $this->assertSame(176.02, $response->json('data.amounts.tax'));
        foreach (['provider_private_marker', 'margin_private_marker', 'issuer_private_marker', 'photo_private_marker'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_internal_failures_are_sanitized_without_destroying_tables(): void
    {
        [, , $id] = $this->fixture();
        $triggered = false;
        DB::listen(function (QueryExecuted $query) use (&$triggered): void {
            if (! $triggered && str_contains($query->sql, 'quote_public_identifiers')) {
                $triggered = true;
                throw new RuntimeException('private-diagnostic-marker SQL '.$query->sql.' '.$this->token);
            }
        });
        $response = $this->read($id);
        $this->assertApiError($response, 500, 'INTERNAL_ERROR', 'Error interno del servicio');
        foreach (['private-diagnostic-marker', 'quote_public_identifiers', $this->token, base_path()] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertTrue($triggered);
        $this->read($id)->assertOk();
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_serialization_preserves_cents_and_restores_the_php_precision_setting(): void
    {
        [, , $id] = $this->fixture();
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '17');
            $response = $this->read($id)->assertOk();
            $this->assertSame('17', ini_get('serialize_precision'));
            $this->assertStringContainsString('"shipping":50.01', $response->getContent());
            $this->assertStringContainsString('"tax":176.02', $response->getContent());
            $this->assertStringContainsString('"total":1325.03', $response->getContent());
            $this->assertStringNotContainsString('176.02000000000001', $response->getContent());
            $this->assertStringNotContainsString('50.009999999999998', $response->getContent());
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    private function read(int|string $id): TestResponse
    {
        return $this->getJson($this->url($id), ['Authorization' => 'Bearer '.$this->token]);
    }

    private function url(int|string $id): string
    {
        return 'http://localhost/api/v1/cotizaciones/'.$id;
    }

    private function assertApiError(TestResponse $response, int $status, string $code, string $message): void
    {
        $response->assertStatus($status)->assertExactJson(['success' => false, 'error' => ['code' => $code, 'message' => $message]])
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $response->headers->get('X-Request-ID'));
    }

    private function assertContract(mixed $expected, mixed $actual, string $path = ''): void
    {
        $nullable = [
            'data.folio' => 'string', 'data.dates.created_at' => 'string', 'data.dates.elaborated_at' => 'string',
            'data.customer.id' => 'integer', 'data.customer.name' => 'string', 'data.customer.company' => 'string',
            'data.customer.email' => 'string', 'data.customer.phone' => 'string', 'data.customer.secondary_phone' => 'string',
            'data.items.*.id' => 'integer', 'data.items.*.sku' => 'string', 'data.items.*.variant_sku' => 'string',
            'data.items.*.name' => 'string', 'data.items.*.description' => 'string', 'data.items.*.color' => 'string', 'data.items.*.printing' => 'string',
            'data.items_html' => 'string', 'data.sale_amount_unavailable_reason' => 'string', 'data.amounts_recorded_at' => 'string',
            'data.comments' => 'string', 'data.pdf.url' => 'string',
        ];
        if (isset($nullable[$path]) && ($actual === null || $expected === null)) {
            if ($actual !== null) {
                $this->assertSame($nullable[$path], gettype($actual), $path);
            }

            return;
        }
        if (is_array($expected)) {
            $this->assertIsArray($actual, $path);
            if (array_is_list($expected)) {
                $this->assertTrue(array_is_list($actual), $path);
                if ($expected !== []) {
                    $this->assertNotEmpty($actual, $path);
                    foreach ($actual as $row) {
                        $this->assertContract($expected[0], $row, $path.'.*');
                    }
                }
            } else {
                $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual), $path);
                foreach ($expected as $key => $value) {
                    $this->assertContract($value, $actual[$key], ltrim($path.'.'.$key, '.'));
                }
            }
        } elseif (str_starts_with($path, 'data.amounts.') && $path !== 'data.amounts.currency') {
            $this->assertTrue(is_float($actual) || is_int($actual), $path.' must be a JSON number');
        } else {
            $this->assertSame(gettype($expected), gettype($actual), $path);
        }
    }

    private function fixture(?array $snapshot = null, string $status = 'issued', bool $grant = true, bool $withVersion = true): array
    {
        $quote = Quote::create(['status' => $status, 'customer' => ['name' => 'Datos actuales distintos', 'email' => 'current@example.invalid'],
            'seller' => 'Asesor ficticio', 'totals' => ['total' => 1], 'created_at' => '2026-11-20 18:00:00']);
        $id = PublicQuoteIdentifiers::NATIVE_MIN + $quote->id - 1;
        QuotePublicIdentifier::create(['public_id' => $id, 'quote_id' => $quote->id, 'origin' => 'laravel', 'source_id' => $quote->uuid, 'created_at' => now()]);
        if ($grant) {
            config(['quote_api.allowed_quote_uuids' => [...config('quote_api.allowed_quote_uuids'), $quote->uuid]]);
        }

        return [$quote, $withVersion ? $this->version($quote, 1, $snapshot ?? $this->snapshot()) : null, $id];
    }

    private function version(Quote $quote, int $number, array $snapshot, string $createdAt = '2026-11-23 18:00:00'): QuoteVersion
    {
        $uuid = (string) Str::uuid();
        $bytes = "%PDF-1.4\n% Fictitious API contract document version {$number}\n%%EOF\n";
        $path = 'quotes/'.$quote->uuid.'/'.$uuid.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return $quote->versions()->create(['uuid' => $uuid, 'version' => $number, 'request_key' => (string) Str::uuid(),
            'snapshot' => $snapshot, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'renderer_version' => 'synthetic-contract', 'created_at' => $createdAt]);
    }

    private function snapshot(int $version = 1): array
    {
        return [
            'version' => $version, 'folio' => 'USB-2026-FICTICIO', 'issued_at' => '2026-11-23T18:00:00+00:00',
            'client_id' => 77, 'customer' => ['name' => 'Contacto ficticio Á & Ω', 'company' => null, 'email' => 'fixture@example.invalid', 'phone' => null],
            'source_request' => ['message' => 'Comentario de la emisión'], 'terms' => 'Condiciones comerciales',
            'issuer' => ['private' => 'issuer_private_marker'], 'tax_basis_points' => 1600,
            'items' => [['product' => ['sku' => 'SKU-FICTICIO', 'name' => 'Producto Á & Ω', 'description' => null, 'color' => 'PL'],
                'quantity' => 50, 'printing' => 'Grabado', 'unit_minor' => 2000, 'printing_minor' => 200, 'discount_minor' => 111,
                'pricing' => ['cost' => 'provider_private_marker', 'margin' => 'margin_private_marker'], 'photo' => ['path' => 'photo_private_marker']]],
            'totals' => ['subtotal' => 110011, 'discount' => 111, 'tax' => 17602, 'shipping' => 5001, 'total' => 132503, 'currency' => 'MXN', 'line_totals' => [110011]],
        ];
    }
}
