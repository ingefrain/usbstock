<?php

namespace App\Http\Controllers;

use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class StorefrontController
{
    public function home()
    {
        return view('shop.home', ['products' => Product::published()->with(['image', 'variants'])->latest('synced_at')->limit(6)->get()]);
    }

    public function catalog(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:120', 'category' => 'nullable|string|max:140', 'capacity' => 'nullable|integer|min:1|max:100000', 'available' => 'nullable|boolean']);
        $products = Product::published()->with(['image', 'variants', 'category'])
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('external_key', 'like', '%'.$s.'%')->orWhereRaw('COALESCE(custom_description, supplier_description) LIKE ?', ['%'.$s.'%'])->orWhere('custom_name', 'like', '%'.$s.'%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', '%'.$s.'%'))))
            ->when($data['category'] ?? null, fn ($q, $s) => $q->whereHas('category', fn ($c) => $c->where('slug', $s)))
            ->when($data['capacity'] ?? null, fn ($q, $s) => $q->where('capacity_gb', $s))
            ->when($data['available'] ?? false, fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('is_enabled', true)->where('provider_active', true)->where('stock', '>=', 50)))
            ->orderBy('external_key')->paginate(18)->withQueryString();

        return view('shop.catalog', ['products' => $products, 'categories' => ProductCategory::where('is_published', true)->orderBy('name')->get(), 'capacities' => Product::published()->whereNotNull('capacity_gb')->distinct()->orderBy('capacity_gb')->pluck('capacity_gb')]);
    }

    public function product(string $slug)
    {
        $product = Product::published()->with(['image', 'category', 'variants' => fn ($q) => $q->where('provider_active', true)->where('is_enabled', true)->orderBy('sku')])->where('slug', $slug)->firstOrFail();
        $related = Product::published()->with(['image', 'variants'])->where('id', '!=', $product->id)->where('product_category_id', $product->product_category_id)->limit(3)->get();

        return view('shop.product', compact('product', 'related'));
    }

    public function image(string $slug)
    {
        $product = Product::published()->where('slug', $slug)->firstOrFail();
        $image = $product->image;
        abort_unless($image && in_array($image->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        abort_unless(app(ManagedDocumentPath::class)->matches($image), 404);

        return Storage::disk($image->disk)->response($image->path, null, ['Content-Type' => $image->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function info()
    {
        return view('shop.info', ['settings' => CommercialSetting::values()]);
    }

    public function sitemap()
    {
        $products = Product::published()->orderBy('id')->get(['slug', 'updated_at']);

        return response()->view('shop.sitemap', compact('products'))->header('Content-Type', 'application/xml');
    }
}
