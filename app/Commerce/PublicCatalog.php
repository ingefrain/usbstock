<?php

namespace App\Commerce;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class PublicCatalog
{
    public function filters(array $data): array
    {
        return array_filter([
            'q' => trim($data['q'] ?? ''),
            'category' => $data['category'] ?? '',
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
        ], fn ($value) => $value !== '' && $value !== null);
    }

    public function query(array $filters): Builder
    {
        // Only public presentation fields; do not hydrate variant costs or all variants.
        return Product::catalogVisible()->select(['products.id', 'uuid', 'external_key', 'slug', 'custom_name', 'custom_description', 'supplier_description', 'capacity_gb'])
            ->addSelect(['category_name' => ProductCategory::select('name')->whereColumn('product_categories.id', 'products.product_category_id')->limit(1)])
            ->addSelect(['assigned_category_name' => ProductCategory::publiclyVisible()->select('name')->whereExists(fn ($q) => $q->selectRaw('1')->from('product_category_assignments')->whereColumn('product_category_assignments.product_category_id', 'product_categories.id')->whereColumn('product_category_assignments.product_id', 'products.id'))->orderBy('sort_order')->orderBy('id')->limit(1)])
            ->with('image')
            ->withCount(['variants as available_variants_count' => fn ($q) => $q->where('provider_active', true)->where('is_enabled', true)->where('stock', '>=', 50)])
            ->when($filters['q'] ?? null, function ($query, $search): void {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $query->where(fn ($q) => $q->whereRaw("external_key LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("COALESCE(custom_description, supplier_description) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("custom_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('variants', fn ($v) => $v->commerciallyAvailable()->whereRaw("sku LIKE ? ESCAPE '!'", [$like])));
            })
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->inCategories(ProductCategory::publiclyVisible()->where('slug', $slug)->first()?->branchIds() ?? []))
            ->when($filters['capacity'] ?? null, fn ($q, $capacity) => $q->where('capacity_gb', $capacity));
    }

    public function block(array $filters, ?string $cursor = null): array
    {
        $revision = (int) DB::table('commerce_locks')->where('id', 1)->value('catalog_revision');
        $fingerprint = hash('sha256', json_encode($filters));
        $state = ['at' => now()->timestamp];
        if ($cursor) {
            try {
                $state = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'El enlace de continuación no es válido.');
            }
            abort_unless(is_array($state) && ($state['v'] ?? null) === 1 && is_int($state['after'] ?? null)
                && $state['after'] > 0 && ($state['filters'] ?? null) === $fingerprint && is_int($state['at'] ?? null), 422, 'El cursor no corresponde a estos filtros.');
            abort_if(($state['revision'] ?? null) !== $revision || $state['at'] < now()->subHours(2)->timestamp, 409, 'El catálogo cambió o el enlace venció. Actualiza los resultados.');
        }
        $limit = max(12, min(48, (int) config('commerce.catalog_block_size', 24)));
        $rows = $this->query($filters)->when($cursor, fn ($q) => $q->where('products.id', '<', $state['after']))
            ->orderByDesc('products.id')->limit($limit + 1)->get();
        abort_if($revision !== (int) DB::table('commerce_locks')->where('id', 1)->value('catalog_revision'), 409, 'El catálogo se actualizó. Vuelve a cargar los resultados.');
        $more = $rows->count() > $limit;
        $products = $rows->take($limit)->values();
        $next = $more ? Crypt::encryptString(json_encode(['v' => 1, 'after' => $products->last()->id, 'revision' => $revision, 'filters' => $fingerprint, 'at' => $state['at']])) : null;

        return ['products' => $products, 'revision' => $revision, 'next' => $next,
            'nextUrl' => $next ? route('shop.catalog', $filters + ['cursor' => $next]) : null];
    }
}
