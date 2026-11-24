<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_public_identifiers', function (Blueprint $table): void {
            $table->unsignedBigInteger('public_id')->primary();
            $table->foreignId('quote_id')->unique()->constrained()->restrictOnDelete();
            $table->string('origin', 80);
            $table->string('source_id', 100);
            $table->timestamp('created_at');
            $table->unique(['origin', 'source_id']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Los identificadores públicos deben conservarse; se requiere una migración explícita sin pérdida de correspondencias.');
    }
};
