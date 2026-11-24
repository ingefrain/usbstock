<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_locks', function (Blueprint $table): void {
            // Allocation cursor, not an assertion about the extent of historical IDs.
            $table->unsignedBigInteger('public_quote_sequence')->default(2999);
        });
    }

    public function down(): void
    {
        throw new LogicException('El cursor de identificadores públicos debe conservarse para impedir reutilizaciones.');
    }
};
