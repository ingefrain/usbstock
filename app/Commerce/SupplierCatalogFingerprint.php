<?php

namespace App\Commerce;

use Illuminate\Support\Facades\DB;

final class SupplierCatalogFingerprint
{
    public function capture(): array
    {
        return DB::transaction(function (): array {
            $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $result = ['revision' => (int) $lock->catalog_revision];
            foreach (['products', 'product_variants'] as $table) {
                $hash = hash_init('sha256');
                $count = 0;
                foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                    $count++;
                }
                $result[$table] = ['count' => $count, 'sha256' => hash_final($hash)];
            }

            return $result;
        });
    }
}
