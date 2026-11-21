<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $roles = [
            [
                'name' => 'microstore_owner',
                'display_name' => 'Microstore Owner',
                'description' => 'Creator or seller who owns and manages a Limitra MicroStore.',
                'is_system' => true,
            ],
            [
                'name' => 'celebrity_store_owner',
                'display_name' => 'Celebrity Store Owner',
                'description' => 'Celebrity or public figure who owns and manages a Limitra Celebrity Store.',
                'is_system' => true,
            ],
        ];

        $roleIds = [];
        foreach ($roles as $role) {
            $existingId = DB::table('roles')->where('name', $role['name'])->value('id');
            if ($existingId) {
                $roleIds[$role['name']] = (int) $existingId;
                continue;
            }
            $roleIds[$role['name']] = (int) DB::table('roles')->insertGetId(array_merge($role, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        if (! Schema::hasTable('role_user') || ! Schema::hasTable('microstores')) {
            return;
        }

        $stores = DB::table('microstores')->select(['user_id', 'type'])->get();
        foreach ($stores as $store) {
            $roleName = ($store->type === 'celebrity') ? 'celebrity_store_owner' : 'microstore_owner';
            $roleId = $roleIds[$roleName] ?? null;
            if ($roleId && $store->user_id) {
                DB::table('role_user')->insertOrIgnore([
                    'user_id' => $store->user_id,
                    'role_id' => $roleId,
                    'is_primary' => false,
                    'assigned_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $ids = DB::table('roles')
            ->whereIn('name', ['microstore_owner', 'celebrity_store_owner'])
            ->pluck('id');

        if ($ids->isNotEmpty() && Schema::hasTable('role_user')) {
            DB::table('role_user')->whereIn('role_id', $ids)->delete();
        }

        DB::table('roles')->whereIn('name', ['microstore_owner', 'celebrity_store_owner'])->delete();
    }
};
