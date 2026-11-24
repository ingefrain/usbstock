<?php

namespace App\Commerce\Api;

use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Read-only, revocable capabilities for one immutable issued PDF. */
final class QuoteDocument
{
    private const PURPOSE = 'usbstock.quote-pdf.v1';

    // Bound memory use when persisted file metadata is corrupt or unexpected.
    private const MAX_BYTES = 64 * 1024 * 1024;

    public function __construct(private QuoteApiConsumer $consumer) {}

    public function describe(Quote $quote, QuoteVersion $version, int $publicId): array
    {
        $unavailable = ['available' => false, 'url' => null, 'basis' => 'latest_generated_quote_pdf'];
        $base = $this->baseUrl();
        if (! $base || ! $this->permitted($quote) || ! $this->mapped($quote, $publicId)
            || $this->readVerified($quote, $version) === null) {
            return $unavailable;
        }

        $payload = [
            'purpose' => self::PURPOSE,
            'consumer' => config('quote_api.consumer'),
            'public_id' => $publicId,
            'quote_uuid' => $quote->uuid,
            'version_uuid' => $version->uuid,
            'sha256' => $version->sha256,
            'fingerprint' => $this->consumer->fingerprint(),
            'environment' => config('quote_api.environment'),
        ];
        $ticket = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))), '+/', '-_'), '=');

        return ['available' => true, 'url' => $base.'/api/v1/documentos/'.$ticket, 'basis' => 'latest_generated_quote_pdf'];
    }

    public function download(string $ticket): Response
    {
        if (config('quote_api.pdf_enabled') !== true || ! $this->consumer->active()) {
            throw new QuoteUnavailable;
        }
        try {
            if (strlen($ticket) > 4096 || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $ticket)) {
                throw new \UnexpectedValueException;
            }
            $encrypted = base64_decode(strtr($ticket, '-_', '+/'), true);
            if (! is_string($encrypted)) {
                throw new \UnexpectedValueException;
            }
            $payload = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new QuoteUnavailable;
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== self::PURPOSE
            || ($payload['consumer'] ?? null) !== config('quote_api.consumer')
            || ($payload['environment'] ?? null) !== config('quote_api.environment')
            || ! is_int($payload['public_id'] ?? null) || $payload['public_id'] < 1
            || ! is_string($payload['quote_uuid'] ?? null) || ! Str::isUuid($payload['quote_uuid'])
            || ! is_string($payload['version_uuid'] ?? null) || ! Str::isUuid($payload['version_uuid'])
            || ! is_string($payload['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $payload['sha256'])
            || ! is_string($payload['fingerprint'] ?? null)
            || ! hash_equals($this->consumer->fingerprint(), $payload['fingerprint'])) {
            throw new QuoteUnavailable;
        }

        $quote = Quote::where('uuid', $payload['quote_uuid'])->first();
        if (! $quote || ! $this->permitted($quote) || ! $this->mapped($quote, $payload['public_id'])) {
            throw new QuoteUnavailable;
        }
        $version = $quote->versions()->where('uuid', $payload['version_uuid'])->first();
        if (! $version || ! is_string($version->sha256) || ! hash_equals($version->sha256, $payload['sha256'])
            || ($bytes = $this->readVerified($quote, $version)) === null) {
            throw new QuoteUnavailable;
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cotizacion-'.$payload['public_id'].'-v'.(int) $version->version.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function permitted(Quote $quote): bool
    {
        return config('quote_api.pdf_enabled') === true && $this->consumer->active() && $this->consumer->canRead($quote);
    }

    private function mapped(Quote $quote, int $publicId): bool
    {
        return $publicId > 0 && QuotePublicIdentifier::where('public_id', $publicId)->where('quote_id', $quote->id)->exists();
    }

    private function baseUrl(): ?string
    {
        $value = config('quote_api.base_url');
        if (! is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)
            || ($parts['scheme'] !== 'https' && ! in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return null;
        }

        return rtrim($value, '/');
    }

    private function readVerified(Quote $quote, QuoteVersion $version): ?string
    {
        $size = filter_var($version->size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => self::MAX_BYTES]]);
        if ((int) $version->quote_id !== (int) $quote->id || ! Str::isUuid($quote->uuid) || ! Str::isUuid($version->uuid)
            || ! is_string($version->sha256) || ! preg_match('/\A[a-f0-9]{64}\z/D', $version->sha256)
            || $size === false
            || config('filesystems.disks.local.driver') !== 'local') {
            return null;
        }
        $expected = 'quotes/'.$quote->uuid.'/'.$version->uuid.'.pdf';
        if ($version->path !== $expected) {
            return null;
        }
        $stream = null;
        try {
            $disk = Storage::disk('local');
            $root = $disk->path('');
            $path = $disk->path($expected);
            $realRoot = realpath($root);
            $realPath = realpath($path);
            if ($realRoot === false || $realPath === false || ! is_file($realPath)
                || ! str_starts_with($this->normalized($realPath), rtrim($this->normalized($realRoot), '/').'/')) {
                return null;
            }
            $cursor = rtrim($root, '/\\');
            if (is_link($cursor)) {
                return null;
            }
            foreach (explode('/', $expected) as $segment) {
                $cursor .= DIRECTORY_SEPARATOR.$segment;
                if (is_link($cursor)) {
                    return null;
                }
            }
            $stream = $disk->readStream($expected);
            if (! is_resource($stream)) {
                return null;
            }
            $stat = fstat($stream);
            if (! $stat || $stat['size'] !== $size) {
                return null;
            }
            $bytes = '';
            $hash = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    return null;
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $size) {
                    return null;
                }
                hash_update($hash, $chunk);
            }
            if (strlen($bytes) !== $size || ! str_starts_with($bytes, '%PDF-')
                || ! hash_equals($version->sha256, hash_final($hash))) {
                return null;
            }

            return $bytes;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function normalized(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
