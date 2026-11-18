<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_sellers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 180);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::table('commercial_clients', fn (Blueprint $table) => $table->string('country', 100)->nullable());
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->boolean('has_seller')->nullable(); // Unknown for pre-existing requests.
            $table->string('seller_name', 180)->nullable(); // Original, never normalized or reassigned.
            $table->foreignId('seller_id')->nullable()->constrained('commercial_sellers')->restrictOnDelete();
            $table->foreignId('seller_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seller_confirmed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->constrained('commercial_sellers')->restrictOnDelete();
            $table->string('seller_original_name', 180)->nullable();
        });
        DB::table('permissions')->insertOrIgnore(['key' => 'sellers.manage', 'description' => 'Administrar el directorio comercial de vendedores', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('roles')->where('key', 'administrator')->value('id');
        $permission = DB::table('permissions')->where('key', 'sellers.manage')->value('id');
        if ($role && $permission) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        // Reverting this additive migration would destroy captured attribution.
        throw new RuntimeException('Commercial attribution requires an explicit data-preserving rollback plan.');
    }
};
