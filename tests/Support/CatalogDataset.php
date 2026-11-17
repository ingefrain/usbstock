<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CatalogDataset
{
    public static function create(int $count, int $variants = 4): void
    {
        TestDatabaseSafety::assertConnection(DB::connection()->getConfig(), getenv('OUTBOX_MARIADB_TEST') ?: null);
        $offset = (int) DB::table('products')->max('id');
        $category = DB::table('product_categories')->insertGetId(['name' => 'Categoría ficticia '.$offset, 'slug' => 'prueba-'.$offset, 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        for ($start = 1; $start <= $count; $start += 250) {
            $products = [];
            $rows = [];
            for ($i = $start; $i <= min($count, $start + 249); $i++) {
                $id = $offset + $i;
                $model = 'TEST'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
                $products[] = ['id' => $id, 'uuid' => (string) Str::uuid(), 'external_key' => $model, 'slug' => strtolower($model), 'custom_name' => 'Memoria sintética '.$model, 'supplier_description' => 'Datos ficticios para pruebas aisladas. Memoria USB con acabado metálico, lista para personalizar.', 'capacity_gb' => $i % 2 ? 16 : 32, 'product_category_id' => $category, 'is_published' => true, 'provider_active' => true, 'created_at' => now(), 'updated_at' => now()];
                foreach (range(1, $variants) as $v) {
                    $rows[] = ['uuid' => (string) Str::uuid(), 'product_id' => $id, 'sku' => $model.'-V'.$v, 'color_code' => 'V'.$v, 'stock' => $i % 3 ? 500 : 0, 'supplier_price_minor' => 987654, 'supplier_description' => 'Variante ficticia', 'provider_active' => true, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            DB::table('products')->insert($products);
            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('product_variants')->insert($chunk);
            }
        }
    }
}
