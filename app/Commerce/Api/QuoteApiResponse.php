<?php

namespace App\Commerce\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class QuoteApiResponse
{
    public static function isApi(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function success(array $data): JsonResponse
    {
        $previous = ini_get('serialize_precision');
        try {
            if ($previous !== '-1' && ini_set('serialize_precision', '-1') === false) {
                throw new \RuntimeException('No se pudo configurar la representación decimal.');
            }

            return new JsonResponse(['success' => true, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } finally {
            if ($previous !== '-1') {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    public static function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function render(Throwable $error, Request $request): ?JsonResponse
    {
        if (! self::isApi($request)) {
            return null;
        }
        if ($error instanceof QuoteApiException) {
            return self::error($error->status, $error->errorCode, $error->getMessage());
        }
        if ($error instanceof QuoteUnavailable) {
            return self::error(404, 'QUOTE_NOT_FOUND', 'Cotización no encontrada');
        }
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 404) {
            return self::error(404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada');
        }
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 405) {
            return self::error(405, 'METHOD_NOT_ALLOWED', 'Sólo se permite el método GET', ['Allow' => 'GET']);
        }
        // Never report raw exception messages, SQL, stack traces or request bodies.
        $request->attributes->set('quote_api_exception', get_class($error));

        return self::error(500, 'INTERNAL_ERROR', 'Error interno del servicio');
    }
}
