<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->index(['product_id', 'provider_active', 'is_enabled', 'stock'], 'variants_public_stock_idx'));
        Schema::table('products', fn (Blueprint $table) => $table->index(['is_published', 'provider_active', 'capacity_gb', 'id'], 'products_public_capacity_idx'));
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropIndex('variants_public_stock_idx'));
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_public_capacity_idx'));
    }
};
