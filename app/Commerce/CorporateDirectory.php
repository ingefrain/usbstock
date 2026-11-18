<?php

namespace App\Commerce;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CorporateDirectory
{
    private const ENDPOINT = 'https://grupotalento.com.mx/includes/vendedores.php';

    private const MAX_BYTES = 65536;

    private function cacheKey(): string
    {
        return 'corporate-directory:'.hash('sha256', (string) config('commerce.corporate_contacts.key'));
    }

    public function sellers(): array
    {
        if (! config('commerce.corporate_contacts.enabled') || ! filled(config('commerce.corporate_contacts.key'))) {
            throw new RuntimeException('El directorio corporativo no está disponible por el momento.');
        }
        $key = $this->cacheKey();
        if (is_array($data = Cache::get($key))) {
            return $data;
        }
        if (Cache::has($key.':unavailable')) {
            throw new RuntimeException('El directorio corporativo no está disponible por el momento.');
        }
        $lock = Cache::lock($key.':lock', 6);
        if (! $lock->get()) {
            throw new RuntimeException('El directorio se está actualizando. Inténtalo en unos momentos.');
        }
        try {
            if (is_array($data = Cache::get($key))) {
                return $data;
            }
            $raw = $this->request(['data' => 1]);
            if (! is_array($raw) || isset($raw['error']) || count($raw) > 80) {
                throw new RuntimeException('Invalid directory.');
            }
            $sellers = [];
            foreach ($raw as $row) {
                if (! is_array($row) || ! isset($row['id'], $row['nombre'], $row['telefono'], $row['estatus'])
                    || (! is_int($row['id']) && ! is_string($row['id']))
                    || ! preg_match('/\A[1-9][0-9]{0,8}\z/', (string) $row['id'])
                    || ! is_string($row['nombre']) || trim($row['nombre']) === '' || mb_strlen($row['nombre']) > 100
                    || preg_match('/[<>\x00-\x1F\x7F]/u', $row['nombre'])
                    || ! in_array($row['estatus'], [0, 1, '0', '1'], true)) {
                    throw new RuntimeException('Invalid seller.');
                }
                $id = (int) $row['id'];
                if (isset($sellers[$id])) {
                    throw new RuntimeException('Duplicate seller.');
                }
                $phone = is_string($row['telefono']) || is_int($row['telefono']) ? (string) $row['telefono'] : '';
                if (! preg_match('/\A\+?[0-9 ()-]{10,24}\z/', $phone)) {
                    $phone = '';
                }
                $phone = preg_replace('/[^0-9]/', '', $phone);
                if (! preg_match('/\A[1-9][0-9]{9,14}\z/', $phone)) {
                    $phone = null;
                }
                $available = (int) $row['estatus'] === 1;
                $sellers[$id] = ['id' => $id, 'name' => trim($row['nombre']), 'photo' => $this->photo($row['foto'] ?? null),
                    'available' => $available, 'url' => $available && $phone ? 'https://wa.me/'.$phone.'?text='.rawurlencode('Hola, me gustaría recibir información sobre USB Stock.') : null];
            }
            $data = array_values($sellers);
            Cache::put($key, $data, 120);

            return $data;
        } catch (Throwable) {
            Cache::put($key.':unavailable', true, 20);
            throw new RuntimeException('No pudimos consultar el directorio. Inténtalo de nuevo en unos momentos.');
        } finally {
            $lock->release();
        }
    }

    private function request(array $parameters): array
    {
        // Never accept a URL/host from a visitor. Never retry an upstream request automatically.
        $response = Http::acceptJson()->connectTimeout(2)->timeout(4)->withoutRedirecting()
            ->withOptions(['stream' => true, 'on_headers' => function ($response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new RuntimeException('Oversize response.');
                }
            }])->get(self::ENDPOINT, ['key' => config('commerce.corporate_contacts.key')] + $parameters);
        if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'application/json')) {
            throw new RuntimeException('Directory unavailable.');
        }
        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        try {
            while (! $body->eof() && strlen($bytes) <= self::MAX_BYTES) {
                $part = $body->read(min(8192, self::MAX_BYTES + 1 - strlen($bytes)));
                if ($part === '' && ! $body->eof()) {
                    throw new RuntimeException('Incomplete response.');
                }
                $bytes .= $part;
            }
        } finally {
            $body->close();
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Oversize response.');
        }
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid response.');
        }

        return $data;
    }

    private function photo(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 400) {
            return null;
        }
        $url = parse_url($value);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'grupotalento.com.mx'
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443) || str_contains(rawurldecode($url['path'] ?? ''), '..')
            || ! preg_match('/\A\/[A-Za-z0-9_\/.% -]+\.(jpg|jpeg|png|webp)\z/i', $url['path'] ?? '')) {
            return null;
        }

        return $value;
    }

    public function record(int $id, string $nonce): bool
    {
        $seller = collect($this->sellers())->firstWhere('id', $id);
        if (! $seller || ! $seller['available'] || ! $seller['url']) {
            return false;
        }
        if (! config('commerce.corporate_contacts.record_clicks')) {
            return false;
        }
        if (! Cache::add('corporate-click:'.hash('sha256', $nonce), true, 600)) {
            return false;
        }
        try {
            $response = $this->request(['save' => 1, 'id' => $id]);
            $ok = ($response['success'] ?? null) === true && in_array($response['id'] ?? null, [$id, (string) $id], true);
            Cache::forget($this->cacheKey());

            return $ok;
        } catch (Throwable) {
            return false; // Contact navigation must not depend on statistics.
        }
    }
}
