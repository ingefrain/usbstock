<?php

namespace App\Http\Controllers;

use App\Commerce\PublicQuoteRequests;
use App\Commerce\SellerPreference;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicQuoteRequestController
{
    public function index(Request $request)
    {
        if ($request->session()->has('quote_request_receipt')) {
            return redirect()->route('shop.request.received');
        }
        if (! $request->session()->has('quote_request_key')) {
            $request->session()->put('quote_request_key', (string) Str::uuid());
        }
        $cart = $request->session()->get('commerce_cart', []);
        $variants = ProductVariant::with('product.image', 'product.category')->whereIn('id', array_keys($cart))->get();
        $sellerPreference = app(SellerPreference::class)->read($request);

        return response()->view('shop.request', compact('cart', 'variants', 'sellerPreference'))->header('Cache-Control', 'no-store, private');
    }

    public function add(Request $request)
    {
        $data = $request->validate(['variant' => 'required|uuid', 'quantity' => 'required|integer|min:50|max:1000000', 'printing' => 'nullable|string|max:500']);
        $v = ProductVariant::with('product.category')->where('uuid', $data['variant'])->firstOrFail();
        abort_unless($v->provider_active && $v->is_enabled && $v->product->isPublic(), 404);
        if ($data['quantity'] > $v->stock) {
            return back()->withErrors(['quantity' => 'La cantidad supera las existencias disponibles.'])->withInput();
        }
        // A new selection after a receipt starts a new request; the previous receipt
        // remains available until a valid product is actually selected.
        if ($request->session()->has('quote_request_receipt')) {
            $request->session()->forget(['commerce_cart', 'commerce_cart_printing', 'quote_request_key', 'quote_request_receipt', '_old_input']);
        }
        $cart = $request->session()->get('commerce_cart', []);
        abort_if(count($cart) >= 50 && ! isset($cart[$v->id]), 422);
        $cart[$v->id] = (int) $data['quantity'];
        ksort($cart);
        $request->session()->put('commerce_cart', $cart);
        $printing = $request->session()->get('commerce_cart_printing', []);
        if (array_key_exists('printing', $data)) {
            $printing[$v->id] = $data['printing'];
        }
        $request->session()->put('commerce_cart_printing', $printing);
        $request->session()->put('quote_request_key', (string) Str::uuid());

        return redirect()->route('shop.request')->with('selection_notice', 'Tu selección está actualizada. Puedes seguir agregando productos.');
    }

    public function remove(Request $request, string $variant)
    {
        $cart = $request->session()->get('commerce_cart', []);
        unset($cart[$variant]);
        $printing = $request->session()->get('commerce_cart_printing', []);
        unset($printing[$variant]);
        $request->session()->put('commerce_cart_printing', $printing);
        $request->session()->put('commerce_cart', $cart);
        $request->session()->put('quote_request_key', (string) Str::uuid());

        return back()->with('selection_notice', 'Quitamos el producto de tu selección.');
    }

    public function submit(Request $request, PublicQuoteRequests $service)
    {
        $data = $request->validate(['name' => 'required|string|max:180', 'email' => 'required|email|max:180', 'phone' => 'required|string|max:50', 'company' => 'nullable|string|max:180', 'country' => 'nullable|string|max:100', 'message' => 'nullable|string|max:2000', 'has_seller' => 'required|boolean', 'seller_name' => ['nullable', 'required_if:has_seller,1', 'string', 'max:180', 'not_regex:/[\x00-\x1F\x7F]/u'], 'website' => 'nullable|size:0', 'request_key' => 'required|uuid']);
        abort_unless(hash_equals((string) $request->session()->get('quote_request_key'), $data['request_key']), 419);
        $key = hash('sha256', $data['request_key']);
        $record = $service->submit(collect($data)->only(['name', 'email', 'phone', 'company', 'country'])->all(), $request->session()->get('commerce_cart', []), $data['message'] ?? null, $key, collect($data)->only(['has_seller', 'seller_name'])->all(), $request->session()->get('commerce_cart_printing', []));
        $request->session()->put('quote_request_receipt', ['reference' => substr($record->uuid, 0, 8), 'uuid' => $record->uuid]);

        return redirect()->route('shop.request.received')->with('request_received', substr($record->uuid, 0, 8))->withCookie(app(SellerPreference::class)->cookie($request, $record->has_seller ? $record->seller_name : null));
    }

    public function received(Request $request)
    {
        abort_unless($request->session()->has('quote_request_receipt'), 404);

        return response()->view('shop.received', ['receipt' => $request->session()->get('quote_request_receipt')])->header('Cache-Control', 'no-store, private');
    }

    public function restart(Request $request)
    {
        $request->session()->forget(['commerce_cart', 'commerce_cart_printing', 'quote_request_key', 'quote_request_receipt']);

        return redirect()->route('shop.catalog');
    }

    public function forgetSeller(Request $request)
    {
        return redirect()->route('shop.request')->withCookie(app(SellerPreference::class)->cookie($request, null))->with('seller_forgotten', true);
    }
}
