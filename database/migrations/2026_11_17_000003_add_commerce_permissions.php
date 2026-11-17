<?php

use App\Commerce\CommercePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (CommercePermissions::DEFINITIONS as $key => $description) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
        }
        $role = DB::table('roles')->where('key', 'administrator')->value('id');
        if ($role) {
            foreach (DB::table('permissions')->whereIn('key', array_keys(CommercePermissions::DEFINITIONS))->pluck('id') as $id) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $id, 'role_id' => $role]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(CommercePermissions::DEFINITIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
