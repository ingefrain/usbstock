<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source', 60)->nullable();
            $table->unsignedInteger('source_id')->nullable();
            $table->json('source_metadata')->nullable();
            $table->unique(['source', 'source_id']);
        });
        Schema::create('product_category_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->string('source', 60);
            $table->unsignedInteger('source_id')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'product_category_id'], 'product_category_assignment_pair_unique');
            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_assignments');
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropUnique(['source', 'source_id']);
            $table->dropColumn(['sort_order', 'source', 'source_id', 'source_metadata']);
        });
    }
};
