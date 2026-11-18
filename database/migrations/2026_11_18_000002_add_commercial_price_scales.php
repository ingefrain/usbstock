<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_price_scales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->unsignedBigInteger('lower_minor');
            $table->unsignedBigInteger('upper_minor')->nullable();
            $table->unsignedInteger('increment_basis_points');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->string('source_reference', 500);
            $table->string('source_key', 100)->nullable()->unique();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'lower_minor']);
        });
        Schema::table('quote_items', function (Blueprint $table): void {
            $table->json('pricing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', fn (Blueprint $table) => $table->dropColumn('pricing'));
        Schema::dropIfExists('commercial_price_scales');
    }
};
