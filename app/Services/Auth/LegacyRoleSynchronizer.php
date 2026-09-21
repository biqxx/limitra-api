<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\User\Role;
use Illuminate\Support\Facades\DB;

class LegacyRoleSynchronizer
{
    private const LEGACY_ROLES = ['user', 'affiliate', 'staff', 'admin'];

    public function synchronize(User $user): void
    {
        $roles = Role::query()
            ->whereIn('name', self::LEGACY_ROLES)
            ->where('is_system', true)
            ->pluck('id', 'name');

        $targetRoleId = $roles->get($user->role);

        if ($targetRoleId === null) {
            return;
        }

        DB::transaction(function () use ($roles, $targetRoleId, $user): void {
            DB::table('role_user')
                ->where('user_id', $user->getKey())
                ->whereIn('role_id', $roles->values())
                ->where('role_id', '!=', $targetRoleId)
                ->delete();

            DB::table('role_user')
                ->where('user_id', $user->getKey())
                ->update([
                    'is_primary' => false,
                    'updated_at' => now(),
                ]);

            $now = now();

            DB::table('role_user')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'role_id' => $targetRoleId,
                'assigned_by' => null,
                'is_primary' => true,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('role_user')
                ->where('user_id', $user->getKey())
                ->where('role_id', $targetRoleId)
                ->update([
                    'is_primary' => true,
                    'updated_at' => $now,
                ]);
        });

        $user->unsetRelation('roles');
    }
}
