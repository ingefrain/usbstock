<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\CommerceAccess;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ProductController
{
    public function index(Request $request)
    {
        CommerceAccess::authorize('products.view');
        $filters = $request->validate(['q' => 'nullable|string|max:120', 'status' => 'nullable|in:published,draft']);
        $products = Product::with('category')->withCount('variants')
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($s) => $s->where('external_key', 'like', '%'.$text.'%')->orWhere('supplier_description', 'like', '%'.$text.'%')->orWhere('custom_name', 'like', '%'.$text.'%')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_published', $status === 'published'))
            ->orderBy('external_key')->paginate(24)->withQueryString();

        return view('admin.commerce.products', compact('products', 'filters'));
    }

    public function edit(Product $product)
    {
        CommerceAccess::authorize('products.view');
        $product->load(['variants', 'image']);

        return view('admin.commerce.product', ['product' => $product, 'categories' => ProductCategory::orderBy('name')->get()]);
    }

    public function update(Request $request, Product $product, DocumentService $documents, AdminAuditLogger $audit)
    {
        CommerceAccess::authorize('products.view');
        CommerceAccess::authorize('products.manage');
        $data = $request->validate([
            'custom_name' => 'nullable|string|max:180', 'custom_description' => 'nullable|string|max:4000',
            'product_category_id' => 'nullable|integer|exists:product_categories,id', 'capacity_gb' => 'nullable|integer|min:1|max:100000',
            'is_published' => 'required|boolean', 'image' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_image' => 'sometimes|boolean', 'variants' => 'sometimes|array|max:200',
            'variants.*.id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
            'variants.*.color_name' => 'nullable|string|max:80', 'variants.*.is_enabled' => 'required|boolean',
        ]);
        DB::transaction(function () use ($data, $request, $product, $documents, $audit): void {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->fill(collect($data)->except(['image', 'remove_image', 'variants'])->all())->save();
            $actor = app(ImpersonationManager::class)->actor() ?? $request->user();
            if (($request->hasFile('image') || ($data['remove_image'] ?? false)) && $product->image) {
                $documents->delete($product->image, $actor);
            }
            if ($request->hasFile('image')) {
                $documents->upload($request->file('image'), $product, $actor, Product::IMAGE_CATEGORY, ['title' => 'Imagen de producto']);
            }
            foreach ($data['variants'] ?? [] as $variant) {
                $product->variants()->whereKey($variant['id'])->update(['color_name' => $variant['color_name'] ?? null, 'is_enabled' => (bool) $variant['is_enabled']]);
            }
            $audit->record('product.updated', 'product', $product->uuid, after: ['fields' => array_keys($data), 'published' => $product->is_published]);
        });

        return back()->with('notify', ['type' => 'success', 'message' => 'Producto actualizado.']);
    }

    public function categories()
    {
        CommerceAccess::authorize('products.view');

        return view('admin.commerce.categories', ['categories' => ProductCategory::withCount('products')->orderBy('name')->get()]);
    }

    public function saveCategory(Request $request, AdminAuditLogger $audit, ?ProductCategory $category = null)
    {
        CommerceAccess::authorize('products.view');
        CommerceAccess::authorize('products.manage');
        $category ??= new ProductCategory;
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => ['required', 'alpha_dash:ascii', 'max:140', Rule::unique('product_categories')->ignore($category->id)], 'description' => 'nullable|string|max:2000', 'is_published' => 'required|boolean']);
        DB::transaction(function () use ($category, $data, $audit): void {
            $category->fill($data)->save();
            $audit->record('product_category.saved', 'product_category', $category->id, after: ['published' => $category->is_published]);
        });

        return back()->with('notify', ['type' => 'success', 'message' => 'Categoría guardada.']);
    }
}
