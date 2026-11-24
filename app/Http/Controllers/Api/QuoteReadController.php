<?php

namespace App\Http\Controllers\Api;

use App\Commerce\Api\QuoteApiConsumer;
use App\Commerce\Api\QuoteApiException;
use App\Commerce\Api\QuoteApiResponse;
use App\Commerce\Api\QuoteDocument;
use App\Commerce\Api\QuotePresenter;
use App\Commerce\Api\QuoteUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuotePublicIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class QuoteReadController extends Controller
{
    public function show(Request $request, QuoteApiConsumer $consumer, QuotePresenter $presenter, QuoteDocument $documents, ?string $id = null)
    {
        $path = $request->getPathInfo();
        if ($path === '/api/v1/cotizaciones') {
            throw new QuoteApiException(404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada');
        }
        // Laravel trims trailing slashes in route parameters; validate the original path.
        $id = rawurldecode(substr($path, strlen('/api/v1/cotizaciones/')));
        if (str_ends_with($id, '/')) {
            $id = substr($id, 0, -1);
        }
        if (! is_string($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1) {
            throw new QuoteApiException(400, 'INVALID_ID', 'ID de cotización inválido');
        }
        $publicId = (int) $id;
        $request->attributes->set('quote_api_public_id', $publicId);

        return DB::transaction(function () use ($publicId, $consumer, $presenter, $documents) {
            $identity = QuotePublicIdentifier::query()->where('public_id', $publicId)->first();
            $quote = $identity ? Quote::query()->find($identity->quote_id) : null;
            // A revision under preparation is unavailable until it is issued again.
            if (! $quote || $quote->status !== 'issued' || ! $consumer->canRead($quote)) {
                throw new QuoteUnavailable;
            }
            $version = $quote->versions()->first();
            if (! $version) {
                throw new QuoteUnavailable;
            }
            $data = $presenter->present($quote, $version, $publicId, ['available' => false, 'url' => null, 'basis' => 'latest_generated_quote_pdf']);
            $data['pdf'] = $documents->describe($quote, $version, $publicId);

            return QuoteApiResponse::success($data);
        });
    }

    public function pdf(Request $request, QuoteDocument $documents, string $ticket)
    {
        $response = $documents->download($ticket);
        $request->attributes->set('quote_api_consumer', config('quote_api.consumer'));
        $bucket = 'quote-api:pdf:'.config('quote_api.consumer');
        $maximum = max(1, min(10000, (int) config('quote_api.pdf_rate_limit', 30)));
        if (RateLimiter::tooManyAttempts($bucket, $maximum)) {
            return QuoteApiResponse::error(429, 'RATE_LIMITED', 'Demasiadas solicitudes', ['Retry-After' => (string) max(1, RateLimiter::availableIn($bucket))]);
        }
        RateLimiter::hit($bucket, 60);

        return $response;
    }
}
