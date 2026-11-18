<?php

namespace App\Commerce;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CartJsonErrors
{
    public static function render(Throwable $error, Request $request)
    {
        if (! $request->routeIs('shop.cart.*') || ! $request->expectsJson()) {
            return null;
        }
        $status = $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500);
        if ($error instanceof AuthenticationException) {
            $status = 401;
        }
        if ($error instanceof LockTimeoutException) {
            $status = 503;
        }
        $message = match ($status) {
            401, 403 => 'No pudimos autorizar esta acción. Actualiza la página e inténtalo de nuevo.',
            419 => 'Tu sesión caducó. Actualiza la página antes de continuar.',
            409, 404 => 'Cambió tu selección o la disponibilidad. Revisa la cantidad y las variantes antes de continuar.',
            422 => 'Revisa la variante, la cantidad mínima de 50 piezas y la impresión (máximo 500 caracteres).',
            429 => 'Has realizado varias acciones seguidas. Espera un momento antes de continuar.',
            default => 'No pudimos confirmar el cambio. Revisa tu cotización antes de intentarlo de nuevo.',
        };
        $data = ['success' => false, 'message' => $message];
        if ($status === 409 && $request->hasSession()) {
            $cart = app(PublicCart::class);
            $data['selection'] = $cart->summary($request);
            if ($request->header('X-Selection-View') === 'review') {
                $data['html'] = view('shop.selection', $cart->viewData($request))->render();
            }
        }

        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
