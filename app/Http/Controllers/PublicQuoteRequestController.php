<?php

namespace App\Http\Controllers;

use App\Commerce\PublicQuoteRequests;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicQuoteRequestController
{
    public function index(Request $request)
    {
        if (! $request->session()->has('quote_request_key')) {
            $request->session()->put('quote_request_key', (string) Str::uuid());
        }
        $cart = $request->session()->get('commerce_cart', []);
        $variants = ProductVariant::with('product')->whereIn('id', array_keys($cart))->get();

        return view('shop.request', compact('cart', 'variants'));
    }

    public function add(Request $request)
    {
        $data = $request->validate(['variant' => 'required|uuid', 'quantity' => 'required|integer|min:50|max:1000000']);
        $v = ProductVariant::with('product.category')->where('uuid', $data['variant'])->firstOrFail();
        abort_unless($v->provider_active && $v->is_enabled && $v->product->isPublic(), 404);
        if ($data['quantity'] > $v->stock) {
            return back()->withErrors(['quantity' => 'La cantidad supera las existencias disponibles.'])->withInput();
        }
        $cart = $request->session()->get('commerce_cart', []);
        abort_if(count($cart) >= 50 && ! isset($cart[$v->id]), 422);
        $cart[$v->id] = (int) $data['quantity'];
        ksort($cart);
        $request->session()->put('commerce_cart', $cart);
        $request->session()->put('quote_request_key', (string) Str::uuid());

        return redirect()->route('shop.request');
    }

    public function remove(Request $request, string $variant)
    {
        $cart = $request->session()->get('commerce_cart', []);
        unset($cart[$variant]);
        $request->session()->put('commerce_cart', $cart);
        $request->session()->put('quote_request_key', (string) Str::uuid());

        return back();
    }

    public function submit(Request $request, PublicQuoteRequests $service)
    {
        $data = $request->validate(['name' => 'required|string|max:180', 'email' => 'required|email|max:180', 'phone' => 'required|string|max:50', 'message' => 'nullable|string|max:2000', 'website' => 'nullable|size:0', 'request_key' => 'required|uuid']);
        abort_unless(hash_equals((string) $request->session()->get('quote_request_key'), $data['request_key']), 419);
        $key = hash('sha256', $data['request_key']);
        $record = $service->submit(collect($data)->only(['name', 'email', 'phone'])->all(), $request->session()->get('commerce_cart', []), $data['message'] ?? null, $key);

        return redirect()->route('shop.request')->with('request_received', substr($record->uuid, 0, 8));
    }
}
