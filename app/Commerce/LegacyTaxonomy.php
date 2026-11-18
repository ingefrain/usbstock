<?php

namespace App\Commerce;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Import only a statically exported, reviewed taxonomy. No legacy DB/network connection. */
final class LegacyTaxonomy
{
    public function preview(array $source): array
    {
        if (($source['source'] ?? null) !== 'usbstock2' || ($source['version'] ?? null) !== 1) {
            throw new RuntimeException('Unrecognized taxonomy source.');
        }
        $categories = collect($source['categories'] ?? [])->keyBy('id');
        $models = collect($source['models'] ?? [])->keyBy('id');
        if ($categories->count() !== count($source['categories']) || $models->count() !== count($source['models'])) {
            throw new RuntimeException('Duplicate source identifiers.');
        }
        $slugs = ProductCategory::all(['slug', 'source', 'source_id'])->mapWithKeys(fn ($c) => [$c->slug => $c->source.':'.$c->source_id])->all();
        $prepared = [];
        foreach ($categories->sortBy(fn ($c) => [$c['parent'] !== 0, $c['sort_order'], $c['id']]) as $c) {
            if (! is_int($c['id']) || $c['id'] < 1 || ! is_int($c['parent']) || ($c['parent'] && (! $categories->has($c['parent']) || $categories[$c['parent']]['parent'] !== 0))) {
                throw new RuntimeException('The source is not a valid two-level taxonomy.');
            }
            $name = trim(html_entity_decode($c['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '' || mb_strlen($name) > 120 || strip_tags($name) !== $name) {
                throw new RuntimeException('Invalid category name.');
            }
            $original = (string) ($c['slug'] ?? '');
            $slug = preg_match('/\A[a-z0-9_-]+\z/', $original) ? $original : Str::slug(str_replace('+', '-', $original ?: $name));
            if (array_key_exists($slug, $slugs) && $slugs[$slug] !== 'usbstock2:'.$c['id']) {
                $slug .= '-'.$c['id'];
            }
            if (strlen($slug) > 140 || $slug === '') {
                throw new RuntimeException('Invalid category slug.');
            }
            $slugs[$slug] = 'usbstock2:'.$c['id'];
            $prepared[] = ['source_id' => $c['id'], 'parent_source_id' => $c['parent'], 'name' => $name, 'slug' => $slug, 'sort_order' => max(0, (int) $c['sort_order']), 'source_metadata' => $c];
        }
        $products = Product::all(['id', 'external_key', 'product_category_id'])->keyBy('external_key');
        $sourceKeys = $models->groupBy('key');
        $assignments = [];
        $unmatched = [];
        $ambiguous = [];
        $seen = [];
        foreach ($source['relations'] ?? [] as $relation) {
            $model = $models->get($relation['model_id']);
            if (! $model || ! $categories->has($relation['category_id'])) {
                throw new RuntimeException('Orphan legacy relation.');
            }
            $key = $model['key'];
            if (! $products->has($key)) {
                $unmatched[] = $key;

                continue;
            }
            if ($sourceKeys[$key]->count() !== 1) {
                $ambiguous[] = $key;

                continue;
            }
            $pair = $products[$key]->id.':'.$relation['category_id'];
            if (isset($seen[$pair])) {
                throw new RuntimeException('Duplicate historical category relation requires review.');
            }
            $seen[$pair] = true;
            $assignments[] = ['product_id' => $products[$key]->id, 'model_key' => $key, 'category_source_id' => $relation['category_id'], 'source_id' => $relation['id'], 'model_source_id' => $relation['model_id']];
        }
        $classified = array_unique(array_column($assignments, 'model_key'));

        return ['source_sha256' => hash('sha256', json_encode($source)), 'categories' => $prepared, 'assignments' => $assignments,
            'classified_models' => count($classified), 'unclassified' => $products->keys()->diff($classified)->sort()->values()->all(),
            'ambiguous' => array_values(array_unique($ambiguous)), 'legacy_models_absent_locally' => array_values(array_unique($unmatched)),
            'editorial_primary_preserved' => $products->whereNotNull('product_category_id')->count()];
    }

    public function apply(array $source, int $actor): array
    {
        return DB::transaction(function () use ($source, $actor) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $plan = $this->preview($source);
            $ids = ProductCategory::where('source', 'usbstock2')->pluck('id', 'source_id')->all();
            $created = $linked = 0;
            foreach ($plan['categories'] as $c) {
                if (isset($ids[$c['source_id']])) {
                    continue;
                } // Preserve every later editorial customization.
                $record = ProductCategory::create(['name' => $c['name'], 'slug' => $c['slug'], 'sort_order' => $c['sort_order'],
                    'parent_id' => $c['parent_source_id'] ? ($ids[$c['parent_source_id']] ?? throw new RuntimeException('Missing parent.')) : null,
                    'is_published' => true, 'source' => 'usbstock2', 'source_id' => $c['source_id'], 'source_metadata' => $c['source_metadata']]);
                $ids[$c['source_id']] = $record->id;
                $created++;
            }
            foreach ($plan['assignments'] as $a) {
                $exists = DB::table('product_category_assignments')->where('product_id', $a['product_id'])->where('product_category_id', $ids[$a['category_source_id']])->exists();
                if ($exists) {
                    continue;
                }
                DB::table('product_category_assignments')->insert(['product_id' => $a['product_id'], 'product_category_id' => $ids[$a['category_source_id']],
                    'source' => 'usbstock2', 'source_id' => $a['source_id'], 'source_metadata' => json_encode(['model_id' => $a['model_source_id'], 'model_key' => $a['model_key'], 'source_sha256' => $plan['source_sha256']]), 'created_at' => now(), 'updated_at' => now()]);
                $linked++;
            }
            if ($created || $linked) {
                DB::table('commerce_locks')->where('id', 1)->increment('catalog_revision');
                app(AdminAuditLogger::class)->record('taxonomy.legacy_imported', 'product_category', 'usbstock2', after: ['categories_created' => $created, 'relations_created' => $linked, 'models' => $plan['classified_models'], 'source_sha256' => $plan['source_sha256']], actorId: $actor);
            }

            return ['categories_created' => $created, 'relations_created' => $linked, 'classified_models' => $plan['classified_models'], 'unclassified' => $plan['unclassified'], 'ambiguous' => $plan['ambiguous']];
        });
    }
}
