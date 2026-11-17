<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('catalog_revision')->default(0);
            $table->unsignedBigInteger('quote_sequence')->default(0);
        });
        DB::table('commerce_locks')->insert(['id' => 1]);
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('external_key', 80)->unique();
            $table->string('slug', 140)->unique();
            $table->text('supplier_description');
            $table->string('custom_name', 180)->nullable();
            $table->text('custom_description')->nullable();
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('capacity_gb')->nullable();
            $table->boolean('provider_active')->default(true);
            $table->boolean('is_published')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['is_published', 'provider_active']);
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('color_code', 40);
            $table->string('color_name', 80)->nullable();
            $table->text('supplier_description');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('supplier_price_minor');
            $table->boolean('provider_active')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'provider_active']);
        });
        Schema::create('supplier_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('local');
            $table->string('filename', 180);
            $table->string('path', 200);
            $table->char('sha256', 64);
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('base_revision')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_sync_runs');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('commerce_locks');
    }
};
