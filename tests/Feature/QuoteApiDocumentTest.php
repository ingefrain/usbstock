<?php

namespace Tests\Feature;

use App\Commerce\Api\QuoteDocument;
use App\Commerce\Api\QuoteUnavailable;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use App\Models\QuoteVersion;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuoteApiDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $encryptionKey = random_bytes(32);
        config([
            'app.key' => 'base64:'.base64_encode($encryptionKey),
            'quote_api.enabled' => true,
            'quote_api.pdf_enabled' => true,
            'quote_api.bearer_token' => bin2hex(random_bytes(32)),
            'quote_api.consumer' => 'grupotalento',
            'quote_api.environment' => 'local',
            'quote_api.base_url' => 'http://127.0.0.1:8001',
            'quote_api.allowed_quote_uuids' => [],
        ]);
        Crypt::swap(new Encrypter($encryptionKey, 'AES-256-CBC'));
    }

    private function quote(): array
    {
        $quote = Quote::create([
            'status' => 'issued', 'customer' => ['name' => 'Cliente ficticio', 'email' => 'ficticio@example.invalid'],
            'seller' => 'Asesor ficticio', 'totals' => [],
        ]);
        $publicId = 2147483647 + $quote->id;
        QuotePublicIdentifier::create([
            'public_id' => $publicId, 'quote_id' => $quote->id,
            'origin' => 'laravel', 'source_id' => $quote->uuid, 'created_at' => now(),
        ]);
        config(['quote_api.allowed_quote_uuids' => [...config('quote_api.allowed_quote_uuids'), $quote->uuid]]);

        return [$quote, $this->version($quote), $publicId];
    }

    private function version(Quote $quote, int $number = 1): QuoteVersion
    {
        $uuid = (string) Str::uuid();
        $bytes = "%PDF-1.4\n% Synthetic document test, immutable version {$number}\n%%EOF\n";
        $path = 'quotes/'.$quote->uuid.'/'.$uuid.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return $quote->versions()->create([
            'uuid' => $uuid, 'version' => $number, 'request_key' => (string) Str::uuid(),
            'snapshot' => ['folio' => 'FICTICIO', 'version' => $number], 'path' => $path,
            'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes),
            'renderer_version' => 'synthetic-test', 'created_at' => now(),
        ]);
    }

    private function ticket(array $pdf): string
    {
        $this->assertTrue($pdf['available']);

        return basename(parse_url($pdf['url'], PHP_URL_PATH));
    }

    private function rejects(string $ticket): void
    {
        try {
            app(QuoteDocument::class)->download($ticket);
            $this->fail('An invalid document capability must be unavailable.');
        } catch (QuoteUnavailable) {
            $this->assertTrue(true);
        }
    }

    public function test_capability_is_opaque_uses_configured_origin_and_remains_bound_to_its_version(): void
    {
        [$quote, $version, $id] = $this->quote();
        $service = app(QuoteDocument::class);
        $pdf = $service->describe($quote, $version, $id);
        $ticket = $this->ticket($pdf);
        $this->assertSame('latest_generated_quote_pdf', $pdf['basis']);
        $this->assertStringStartsWith('http://127.0.0.1:8001/api/v1/documentos/', $pdf['url']);
        foreach ([$quote->uuid, $version->uuid, $version->path, config('quote_api.bearer_token')] as $private) {
            $this->assertStringNotContainsString($private, $pdf['url']);
        }
        $original = Storage::disk('local')->get($version->path);
        $this->travel(40)->days();
        $this->version($quote, 2);
        $quote->update(['status' => 'draft']);
        $response = $service->download($ticket);
        $this->assertSame($original, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('-v1.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_current_credential_consumer_scope_and_configuration_revoke_existing_capabilities(): void
    {
        [$quote, $version, $id] = $this->quote();
        $ticket = $this->ticket(app(QuoteDocument::class)->describe($quote, $version, $id));
        foreach ([
            'quote_api.bearer_token' => bin2hex(random_bytes(32)),
            'quote_api.enabled' => false,
            'quote_api.pdf_enabled' => false,
            'quote_api.allowed_quote_uuids' => [],
            'quote_api.consumer' => 'another-consumer',
            'quote_api.environment' => 'another-environment',
        ] as $key => $value) {
            $previous = config($key);
            config([$key => $value]);
            $this->rejects($ticket);
            config([$key => $previous]);
        }
    }

    public function test_corrupt_missing_and_foreign_documents_are_never_offered_or_served(): void
    {
        [$quote, $version, $id] = $this->quote();
        [$other, $foreign] = $this->quote();
        $service = app(QuoteDocument::class);
        $this->assertFalse($service->describe($quote, $foreign, $id)['available']);
        $this->assertFalse($service->describe($quote, $version, $id + 1)['available']);
        $ticket = $this->ticket($service->describe($quote, $version, $id));
        $bytes = Storage::disk('local')->get($version->path);
        foreach ([str_replace('Synthetic', 'Corrupted', $bytes), '', 'not a PDF'] as $corrupt) {
            Storage::disk('local')->put($version->path, $corrupt);
            $this->assertSame(['available' => false, 'url' => null, 'basis' => 'latest_generated_quote_pdf'], $service->describe($quote, $version, $id));
            $this->rejects($ticket);
        }
        Storage::disk('local')->delete($version->path);
        $this->assertFalse($service->describe($quote, $version, $id)['available']);
        $this->rejects($ticket);
        $this->assertTrue($service->describe($other, $foreign, $id + 1)['available']);
    }

    public function test_hash_size_pdf_signature_and_storage_path_are_independently_verified(): void
    {
        [$quote, $version, $id] = $this->quote();
        $service = app(QuoteDocument::class);
        $bytes = Storage::disk('local')->get($version->path);
        foreach (['sha256' => str_repeat('0', 64), 'size' => strlen($bytes) + 1, 'path' => '../outside.pdf'] as $key => $value) {
            $copy = clone $version;
            $copy->$key = $value;
            $this->assertFalse($service->describe($quote, $copy, $id)['available']);
        }
        $bytes = str_replace('%PDF-', 'PLAIN', $bytes);
        Storage::disk('local')->put($version->path, $bytes);
        $version->sha256 = hash('sha256', $bytes);
        $this->assertFalse($service->describe($quote, $version, $id)['available']);
    }

    public function test_invalid_ciphertext_and_incorrect_payload_bindings_fail_closed(): void
    {
        [$quote, $version, $id] = $this->quote();
        [, $foreign] = $this->quote();
        $ticket = $this->ticket(app(QuoteDocument::class)->describe($quote, $version, $id));
        foreach (['', 'invalid', str_repeat('A', 4097), substr($ticket, 0, -10), '../'.$ticket] as $invalid) {
            $this->rejects($invalid);
        }
        $payload = json_decode(Crypt::decryptString(base64_decode(strtr($ticket, '-_', '+/'), true)), true);
        foreach (['purpose' => 'another-purpose', 'public_id' => $id + 1, 'version_uuid' => $foreign->uuid, 'sha256' => str_repeat('0', 64), 'quote_uuid' => (string) Str::uuid(), 'fingerprint' => 'invalid'] as $key => $value) {
            $modified = $payload;
            $modified[$key] = $value;
            $encoded = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode($modified))), '+/', '-_'), '=');
            $this->rejects($encoded);
        }
    }

    public function test_symlink_cannot_substitute_a_document_even_inside_private_storage(): void
    {
        [$quote, $version, $id] = $this->quote();
        $disk = Storage::disk('local');
        $bytes = $disk->get($version->path);
        $disk->put('linked-document.pdf', $bytes);
        $disk->delete($version->path);
        if (! @symlink($disk->path('linked-document.pdf'), $disk->path($version->path))) {
            $this->markTestSkipped('This host does not grant permission to create filesystem symlinks.');
        }

        try {
            $this->assertFalse(app(QuoteDocument::class)->describe($quote, $version, $id)['available']);
        } finally {
            unlink($disk->path($version->path));
        }
    }

    public function test_document_reads_do_not_write_database_or_storage(): void
    {
        [$quote, $version, $id] = $this->quote();
        $files = Storage::disk('local')->allFiles();
        $bytes = Storage::disk('local')->get($version->path);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $pdf = app(QuoteDocument::class)->describe($quote, $version, $id);
        app(QuoteDocument::class)->download($this->ticket($pdf));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($bytes, Storage::disk('local')->get($version->path));
        $this->assertSame('issued', $quote->fresh()->status);
        $this->assertDatabaseCount('quote_versions', 1);
    }

    public function test_pdf_reference_requires_a_safe_configured_base_url(): void
    {
        [$quote, $version, $id] = $this->quote();
        foreach (['http://example.invalid', 'https://user@example.invalid', 'https://example.invalid/?secret=x', 'https://example.invalid/path', 'javascript:alert(1)', "https://example.invalid\n"] as $base) {
            config(['quote_api.base_url' => $base]);
            $this->assertFalse(app(QuoteDocument::class)->describe($quote, $version, $id)['available']);
        }
        config(['quote_api.base_url' => 'https://api.example.invalid']);
        $this->assertStringStartsWith('https://api.example.invalid/api/v1/documentos/', app(QuoteDocument::class)->describe($quote, $version, $id)['url']);
    }
}
