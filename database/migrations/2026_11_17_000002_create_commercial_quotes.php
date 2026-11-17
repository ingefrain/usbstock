<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_clients', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name', 180);
            $t->string('company', 180)->nullable();
            $t->string('email', 180);
            $t->string('phone', 50)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_settings', function (Blueprint $t) {
            $t->id();
            $t->json('data');
            $t->timestamps();
        });
        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('folio', 40)->nullable()->unique();
            $t->foreignId('commercial_client_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 24)->default('draft')->index();
            $t->unsignedInteger('revision')->default(1);
            $t->json('customer');
            $t->string('seller', 180);
            $t->text('terms')->nullable();
            $t->unsignedSmallInteger('validity_days')->default(30);
            $t->unsignedSmallInteger('tax_basis_points')->default(1600);
            $t->unsignedBigInteger('shipping_minor')->default(0);
            $t->json('totals');
            $t->string('calculation_version', 30)->default('legacy_v1');
            $t->timestamps();
        });
        Schema::create('quote_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->json('product');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_minor');
            $t->unsignedBigInteger('printing_minor')->default(0);
            $t->unsignedBigInteger('discount_minor')->default(0);
            $t->string('printing', 500)->nullable();
            $t->unique(['quote_id', 'position']);
        });
        Schema::create('quote_versions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->uuid('request_key')->unique();
            $t->json('snapshot');
            $t->string('path', 200);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size');
            $t->string('renderer_version', 40);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->unique(['quote_id', 'version']);
        });
        Schema::create('quote_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->char('request_key', 64)->unique();
            $t->char('payload_hash', 64);
            $t->json('customer');
            $t->json('items');
            $t->text('message')->nullable();
            $t->string('status', 24)->default('received')->index();
            $t->foreignId('quote_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['quote_requests', 'quote_versions', 'quote_items', 'quotes', 'commercial_settings', 'commercial_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
