<?php

namespace App\Http\Middleware;

use App\Commerce\Api\QuoteApiConsumer;
use App\Commerce\Api\QuoteApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class QuoteApiBoundary
{
    public function __construct(private QuoteApiConsumer $consumer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! QuoteApiResponse::isApi($request)) {
            return $next($request);
        }
        $started = hrtime(true);
        $correlation = bin2hex(random_bytes(16));
        $request->attributes->set('quote_api_request_id', $correlation);
        $document = $request->is('api/v1/documentos/*');

        try {
            $response = $this->dispatch($request, $next, $document);
        } catch (Throwable $error) {
            $response = QuoteApiResponse::render($error, $request);
        }
        $response->headers->set('X-Request-ID', $correlation);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->remove('X-Powered-By');
        if ($response instanceof JsonResponse) {
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        }
        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="Publimarca API"');
        }
        try {
            Log::channel('quote_api')->info('quote_api.read', [
                'request_id' => $correlation,
                'operation' => $document ? 'pdf' : 'quote',
                'consumer' => $request->attributes->get('quote_api_consumer'),
                'public_id' => $request->attributes->get('quote_api_public_id'),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'exception_type' => $request->attributes->get('quote_api_exception'),
            ]);
        } catch (Throwable) {
            // A logging failure cannot expose the request or commercial response.
        }

        return $response->prepare($request);
    }

    private function dispatch(Request $request, Closure $next, bool $document): Response
    {
        if (! in_array($request->getRealMethod(), $document ? ['GET', 'HEAD'] : ['GET'], true)) {
            return QuoteApiResponse::error(405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET', ['Allow' => $document ? 'GET, HEAD' : 'GET']);
        }
        $ipKey = 'quote-api:ip:'.hash('sha256', (string) $request->ip());
        if ($limited = $this->limit($ipKey, (int) config('quote_api.ip_rate_limit', 120))) {
            return $limited;
        }
        if (! $document && ! $this->consumer->authenticates($request)) {
            return QuoteApiResponse::error(401, 'UNAUTHORIZED', 'Autenticación requerida o inválida');
        }
        if (! $document) {
            $request->attributes->set('quote_api_consumer', config('quote_api.consumer'));
        }
        // Invalid public capabilities must not consume the consumer's PDF allowance.
        if (! $document) {
            if ($limited = $this->limit('quote-api:read:'.config('quote_api.consumer'), (int) config('quote_api.read_rate_limit', 60))) {
                return $limited;
            }
        }

        return $next($request);
    }

    private function limit(string $key, int $maximum): ?Response
    {
        $maximum = max(1, min(10000, $maximum));
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            return QuoteApiResponse::error(429, 'RATE_LIMITED', 'Demasiadas solicitudes', ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }
        RateLimiter::hit($key, 60);

        return null;
    }
}
