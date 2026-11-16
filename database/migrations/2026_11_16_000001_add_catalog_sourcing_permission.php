<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'catalog.sourcing'],
            [
                'domain' => 'catalog',
                'description' => 'Search suppliers, queue AI product imports, and manage sourced product pricing.',
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('name', 'catalog.sourcing')->value('id');
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($permissionId && $adminRoleId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'catalog.sourcing')->value('id');

        if ($permissionId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('name', 'catalog.sourcing')->delete();
    }
};

