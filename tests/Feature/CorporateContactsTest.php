<?php

namespace Tests\Feature;

use App\Commerce\CorporateDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CorporateContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetHttp();
        Cache::flush();
    }

    private function resetHttp(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
    }

    private function enable(): void
    {
        config(['commerce.corporate_contacts.enabled' => true, 'commerce.corporate_contacts.key' => 'synthetic-private-test-key', 'commerce.corporate_contacts.record_clicks' => true]);
    }

    private function rows(): array
    {
        return [['id' => 101, 'nombre' => 'Asesora ficticia', 'telefono' => '+1 (202) 555-0101', 'foto' => 'https://grupotalento.com.mx/images/test.jpg', 'estatus' => 1, 'vistas' => 999],
            ['id' => '102', 'nombre' => 'Asesor no disponible', 'telefono' => '12025550102', 'foto' => 'javascript:alert(1)', 'estatus' => '0', 'vistas' => 0]];
    }

    private function fake(mixed $body, int $status = 200, array $headers = []): void
    {
        $this->resetHttp();
        Http::fake(['grupotalento.com.mx/*' => fn () => Http::response(is_array($body) ? json_encode($body) : $body, $status, $headers + ['Content-Type' => 'application/json'])]);
    }

    public function test_disabled_default_has_no_network_or_fake_people(): void
    {
        $this->getJson(route('shop.contacts'))->assertStatus(503)->assertDontSee('synthetic')->assertDontSee('key');
        Http::assertNothingSent();
        $this->get('/')->assertOk()->assertSee('Contactar por WhatsApp')->assertDontSee('Asesora ficticia');
    }

    public function test_safe_public_contract_cache_and_expiry(): void
    {
        $this->enable();
        $this->fake($this->rows());
        $response = $this->getJson(route('shop.contacts'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $sellers = collect($response->json('sellers'))->keyBy('id');
        $this->assertStringStartsWith('https://wa.me/12025550101?', $sellers[101]['url']);
        $this->assertNull($sellers[102]['url']);
        $this->assertNull($sellers[102]['photo']);
        $response->assertDontSee('synthetic-private-test-key')->assertDontSee('vistas')->assertDontSee('telefono');
        $this->getJson(route('shop.contacts'))->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://grupotalento.com.mx/includes/vendedores.php?') && $r['data'] === 1 && $r['key'] === 'synthetic-private-test-key');
        $this->travel(121)->seconds();
        $this->getJson(route('shop.contacts'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_remote_responses_are_bounded_sanitized_and_negatively_cached(): void
    {
        $this->enable();
        $badRows = $this->rows();
        $badRows[0]['id'] = ['bad'];
        $xss = $this->rows();
        $xss[0]['nombre'] = '<script>malicious()</script>';
        $status = $this->rows();
        $status[0]['estatus'] = 'yes';
        foreach (['not-json', '<html>synthetic-private-test-key</html>', 'null', str_repeat('x', 65537), ['error' => 'synthetic-private-test-key'], $badRows, $xss, $status, [$this->rows()[0], $this->rows()[0]], array_fill(0, 81, $this->rows()[0])] as $body) {
            Cache::flush();
            $this->fake($body);
            $this->getJson(route('shop.contacts'))->assertStatus(503)->assertDontSee('synthetic-private-test-key')->assertDontSee('malicious');
        }
        Cache::flush();
        $this->resetHttp();
        Http::fake(['*' => function () {
            throw new ConnectionException('synthetic-private-test-key timeout');
        }]);
        $this->getJson(route('shop.contacts'))->assertStatus(503)->assertDontSee('synthetic-private-test-key');
        $this->getJson(route('shop.contacts'))->assertStatus(503);
        $this->assertTrue(Cache::has('corporate-directory:'.hash('sha256', 'synthetic-private-test-key').':unavailable'));
    }

    public function test_untrusted_photos_phones_redirects_and_mime_are_rejected(): void
    {
        $this->enable();
        foreach (['https://evil.example/test.jpg', 'https://grupotalento.com.mx@evil.example/test.jpg', 'http://grupotalento.com.mx/test.jpg', 'https://grupotalento.com.mx/../test.jpg', 'https://grupotalento.com.mx/test.svg', 'https://grupotalento.com.mx/test.jpg?key=secret'] as $photo) {
            Cache::flush();
            $rows = $this->rows();
            $rows[0]['foto'] = $photo;
            $rows[0]['telefono'] = 'javascript:alert(1)';
            $this->fake($rows);
            $safe = app(CorporateDirectory::class)->sellers()[0];
            $this->assertNull($safe['photo']);
            $this->assertNull($safe['url']);
        }
        foreach ([[302, ['Location' => 'https://evil.example']], [200, ['Content-Type' => 'text/html']], [500, []]] as [$code, $headers]) {
            Cache::flush();
            $this->fake($this->rows(), $code, $headers);
            $this->getJson(route('shop.contacts'))->assertStatus(503);
        }
    }

    public function test_click_tickets_bind_session_expire_reject_arbitrary_ids_and_deduplicate(): void
    {
        $this->enable();
        Http::fake(['grupotalento.com.mx/*' => fn ($r) => Http::response(isset($r['save']) ? ['success' => true, 'id' => 101] : $this->rows(), 200, ['Content-Type' => 'application/json'])]);
        $response = $this->getJson(route('shop.contacts'))->assertOk();
        $ticket = collect($response->json('sellers'))->firstWhere('id', 101)['ticket'];
        $this->postJson(route('shop.contacts.click'), ['id' => 999])->assertUnprocessable();
        $this->postJson(route('shop.contacts.click'), ['ticket' => 'forged'])->assertUnprocessable();
        $this->postJson(route('shop.contacts.click'), ['ticket' => $ticket])->assertStatus(202)->assertJsonPath('recorded', true);
        $this->postJson(route('shop.contacts.click'), ['ticket' => $ticket])->assertStatus(202)->assertJsonPath('recorded', false);
        $this->assertCount(1, Http::recorded(fn ($r) => isset($r['save'])));
        $payload = json_decode(Crypt::decryptString($ticket), true);
        $payload['session'] = 'different';
        $this->postJson(route('shop.contacts.click'), ['ticket' => Crypt::encryptString(json_encode($payload))])->assertUnprocessable();
        $this->travel(6)->minutes();
        $this->postJson(route('shop.contacts.click'), ['ticket' => $ticket])->assertUnprocessable();
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertDatabaseCount('commercial_sellers', 0);
    }

    public function test_metrics_failure_and_disabled_tracking_do_not_remove_contact_url(): void
    {
        $this->enable();
        Http::fake(['grupotalento.com.mx/*' => fn ($r) => isset($r['save']) ? Http::response('failure', 500) : Http::response($this->rows(), 200, ['Content-Type' => 'application/json'])]);
        $seller = collect($this->getJson(route('shop.contacts'))->json('sellers'))->firstWhere('id', 101);
        $this->assertNotNull($seller['url']);
        $this->postJson(route('shop.contacts.click'), ['ticket' => $seller['ticket']])->assertStatus(202)->assertJsonPath('recorded', false);
        $this->postJson(route('shop.contacts.click'), ['ticket' => $seller['ticket']])->assertStatus(202)->assertJsonPath('recorded', false);
        $this->assertCount(1, Http::recorded(fn ($r) => isset($r['save'])));
        config(['commerce.corporate_contacts.record_clicks' => false]);
        $this->assertFalse(app(CorporateDirectory::class)->record(101, 'different-nonce'));
        $this->assertCount(1, Http::recorded(fn ($r) => isset($r['save'])));
    }
}
