<?php

namespace App\Http\Controllers;

use App\Commerce\PublicCart;
use App\Commerce\PublicQuoteRequests;
use App\Commerce\SellerPreference;
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
        $sellerPreference = app(SellerPreference::class)->read($request);

        return response()->view('shop.request', app(PublicCart::class)->viewData($request) + compact('sellerPreference'))->header('Cache-Control', 'no-store, private');
    }

    public function state(Request $request, PublicCart $cart)
    {
        return $cart->response($request);
    }

    public function add(Request $request, PublicCart $cart)
    {
        $item = $cart->add($request);
        if ($request->expectsJson()) {
            return $cart->response($request, $item, 'Tu cotización está actualizada. Puedes seguir explorando.');
        }

        return redirect()->route('shop.request')->with('selection_notice', 'Tu selección está actualizada. Puedes seguir agregando productos.');
    }

    public function remove(Request $request, string $variant, PublicCart $cart)
    {
        $item = $cart->remove($request, $variant);
        if ($request->expectsJson()) {
            return $cart->response($request, $item, 'Quitamos la variante de tu cotización.');
        }

        return back()->with('selection_notice', 'Quitamos el producto de tu selección.');
    }

    public function submit(Request $request, PublicQuoteRequests $service)
    {
        $data = $request->validate(['name' => 'required|string|max:180', 'email' => 'required|email|max:180', 'phone' => 'required|string|max:50', 'company' => 'nullable|string|max:180', 'country' => 'nullable|string|max:100', 'message' => 'nullable|string|max:2000', 'has_seller' => 'required|boolean', 'seller_name' => ['nullable', 'required_if:has_seller,1', 'string', 'max:180', 'not_regex:/[\x00-\x1F\x7F]/u'], 'website' => 'nullable|size:0', 'request_key' => 'required|uuid']);
        abort_unless(hash_equals((string) $request->session()->get('quote_request_key'), $data['request_key']), 419);
        $key = hash('sha256', $data['request_key']);
        $record = $service->submit(collect($data)->only(['name', 'email', 'phone', 'company', 'country'])->all(), $request->session()->get('commerce_cart', []), $data['message'] ?? null, $key, collect($data)->only(['has_seller', 'seller_name'])->all(), $request->session()->get('commerce_cart_printing', []));
        if (! $request->session()->has('quote_request_receipt')) {
            $request->session()->increment('commerce_cart_revision');
        }
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
        $request->session()->increment('commerce_cart_revision');

        return redirect()->route('shop.catalog');
    }

    public function forgetSeller(Request $request)
    {
        return redirect()->route('shop.request')->withCookie(app(SellerPreference::class)->cookie($request, null))->with('seller_forgotten', true);
    }
}
