<?php

namespace App\Http\Controllers;

use App\Commerce\ProductThumbnail;
use App\Commerce\PublicCatalog;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StorefrontController
{
    public function home()
    {
        return view('shop.home', ['products' => app(PublicCatalog::class)->query([])->orderByDesc('products.id')->limit(6)->get()]);
    }

    public function catalog(Request $request, PublicCatalog $catalog, ?string $category = null)
    {
        $input = $this->catalogInput($request);
        if ($category !== null) {
            ProductCategory::where('slug', $category)->where('is_published', true)->firstOrFail();
            $input['category'] = $category;
        }
        $filters = $catalog->filters($input);
        try {
            $block = $catalog->block($filters, $request->input('cursor'));
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [409, 422], true) && $request->filled('cursor')) {
                return redirect()->route('shop.catalog', $filters)->with('catalog_notice', 'El enlace de continuación venció o cambió. Mostramos el catálogo actualizado.');
            }
            throw $error;
        }

        return response()->view('shop.catalog', $block + ['filters' => $filters,
            'categories' => ProductCategory::where('is_published', true)->orderBy('name')->get(),
            'capacities' => Product::catalogVisible()->whereNotNull('capacity_gb')->distinct()->orderBy('capacity_gb')->pluck('capacity_gb')])->header('Cache-Control', 'no-store, private');
    }

    public function catalogBlock(Request $request, PublicCatalog $catalog)
    {
        $filters = $catalog->filters($this->catalogInput($request));
        $block = $catalog->block($filters, $request->input('cursor'));

        return response()->json(['html' => view('shop.cards', $block + ['filters' => $filters])->render(),
            'next' => $block['nextUrl'], 'count' => $block['products']->count(), 'revision' => $block['revision']])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function catalogInput(Request $request): array
    {
        return $request->validate(['q' => 'nullable|string|max:120', 'category' => 'nullable|string|max:140',
            'capacity' => 'nullable|integer|min:1|max:100000', 'available' => 'nullable|boolean', 'cursor' => 'nullable|string|max:2048']);
    }

    public function thumbnail(string $slug, ProductThumbnail $thumbnails)
    {
        $product = Product::catalogVisible()->with('image')->where('slug', $slug)->firstOrFail();
        abort_unless($product->image, 404);

        return $thumbnails->response($product->image);
    }

    public function product(string $slug)
    {
        $product = Product::catalogVisible()->with(['image', 'category', 'variants' => fn ($q) => $q->where('provider_active', true)->where('is_enabled', true)->orderBy('sku')])->where('slug', $slug)->firstOrFail();
        $related = app(PublicCatalog::class)->query([])->where('products.id', '!=', $product->id)->where('product_category_id', $product->product_category_id)->orderByDesc('products.id')->limit(3)->get();

        return view('shop.product', compact('product', 'related'));
    }

    public function image(string $slug)
    {
        $product = Product::catalogVisible()->where('slug', $slug)->firstOrFail();
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
        $products = Product::catalogVisible()->orderBy('id')->get(['slug', 'updated_at']);
        $categories = ProductCategory::where('is_published', true)->orderBy('id')->get(['slug', 'updated_at']);

        return response()->view('shop.sitemap', compact('products', 'categories'))->header('Content-Type', 'application/xml');
    }
}
