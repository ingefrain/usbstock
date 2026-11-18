<?php

namespace App\Commerce;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Session-owned selection. Quantities replace, never accumulate or reserve inventory. */
final class PublicCart
{
    public function summary(Request $request): array
    {
        $cart = $request->session()->has('quote_request_receipt') ? [] : $request->session()->get('commerce_cart', []);

        return ['revision' => (int) $request->session()->get('commerce_cart_revision', 0),
            'line_count' => count($cart), 'piece_count' => array_sum($cart),
            'request_key' => $request->session()->get('quote_request_key')];
    }

    public function viewData(Request $request): array
    {
        $cart = $request->session()->has('quote_request_receipt') ? [] : $request->session()->get('commerce_cart', []);
        $variants = ProductVariant::with('product.image', 'product.category')->whereIn('id', array_keys($cart))->orderBy('id')->get();

        return compact('cart', 'variants');
    }

    public function add(Request $request): array
    {
        $data = $request->validate([
            'variant' => 'required|uuid', 'quantity' => 'required|integer|min:50|max:1000000', 'printing' => 'nullable|string|max:500',
            'revision' => ($request->expectsJson() ? 'required' : 'sometimes').'|integer|min:0',
        ], ['variant.required' => 'Elige una variante.', 'variant.uuid' => 'Elige una variante válida.',
            'quantity.required' => 'Indica la cantidad.', 'quantity.integer' => 'Escribe una cantidad entera.',
            'quantity.min' => 'La cantidad mínima es de 50 piezas por variante.', 'quantity.max' => 'La cantidad excede el límite permitido.',
            'printing.max' => 'La impresión admite hasta 500 caracteres.', 'revision.required' => 'Actualiza la página para revisar tu selección.']);
        $variant = ProductVariant::with('product.category')->where('uuid', $data['variant'])->first();
        if (! $variant || ! $variant->provider_active || ! $variant->is_enabled || ! $variant->product->isPublic()) {
            abort($request->expectsJson() ? 409 : 404);
        }
        if ($variant->stock < CommercialAvailability::MINIMUM || $data['quantity'] > $variant->stock) {
            if ($request->expectsJson()) {
                throw new CartAvailabilityException($variant->stock);
            }
            throw ValidationException::withMessages(['quantity' => CommercialAvailability::problem($variant, (int) $data['quantity'])]);
        }
        $session = $request->session();
        $receipt = $session->has('quote_request_receipt');
        $cart = $receipt ? [] : $session->get('commerce_cart', []);
        $printing = $receipt ? [] : $session->get('commerce_cart_printing', []);
        $text = array_key_exists('printing', $data) ? ($data['printing'] ?? '') : ($printing[$variant->id] ?? '');
        $unchanged = ($cart[$variant->id] ?? null) === (int) $data['quantity'] && ($printing[$variant->id] ?? '') === $text;
        // Repeating the same absolute quantity is harmless; stale different changes are rejected.
        if (! $unchanged) {
            $this->checkRevision($request);
        }
        if (count($cart) >= 50 && ! isset($cart[$variant->id])) {
            throw ValidationException::withMessages(['variant' => 'Puedes seleccionar hasta 50 variantes distintas.']);
        }
        if ($receipt) {
            $session->forget(['commerce_cart', 'commerce_cart_printing', 'quote_request_key', 'quote_request_receipt', '_old_input']);
        }
        if (! $unchanged) {
            $cart[$variant->id] = (int) $data['quantity'];
            ksort($cart);
            if (array_key_exists('printing', $data)) {
                $printing[$variant->id] = $data['printing'];
            }
            $session->put('commerce_cart', $cart);
            $session->put('commerce_cart_printing', $printing);
            $this->changed($request);
        }

        return ['variant' => $variant->uuid, 'quantity' => (int) $cart[$variant->id], 'printing' => $text];
    }

    public function remove(Request $request, string $identifier): array
    {
        $request->validate(['revision' => ($request->expectsJson() ? 'required' : 'sometimes').'|integer|min:0']);
        // Numeric URLs from previously opened HTML forms remain compatible. New UI uses UUIDs.
        $cart = $request->session()->has('quote_request_receipt') ? [] : $request->session()->get('commerce_cart', []);
        // Never resolve or disclose variants that do not belong to this session's selection.
        $variant = ProductVariant::query()->whereIn('id', array_keys($cart))
            ->where(ctype_digit($identifier) ? 'id' : 'uuid', $identifier)->first();
        if ($variant && isset($cart[$variant->id])) {
            $this->checkRevision($request);
            unset($cart[$variant->id]);
            $printing = $request->session()->get('commerce_cart_printing', []);
            unset($printing[$variant->id]);
            $request->session()->put('commerce_cart', $cart);
            $request->session()->put('commerce_cart_printing', $printing);
            $this->changed($request);
        }

        return ['variant' => $variant?->uuid, 'quantity' => 0];
    }

    private function checkRevision(Request $request): void
    {
        if ($request->has('revision')) {
            abort_unless((int) $request->input('revision') === $this->summary($request)['revision'], 409);
        }
    }

    public function changed(Request $request): void
    {
        $request->session()->increment('commerce_cart_revision');
        $request->session()->put('quote_request_key', (string) Str::uuid());
    }

    public function response(Request $request, array $item = [], string $message = '')
    {
        $data = ['success' => true, 'message' => $message, 'selection' => $this->summary($request), 'item' => $item];
        if ($request->header('X-Selection-View') === 'review') {
            $data['html'] = view('shop.selection', $this->viewData($request))->render();
        }

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
