<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\User\Role;
use Illuminate\Support\Collection;

class PermissionResolver
{
    /** @return list<string> */
    public function resolve(User $user): array
    {
        $roles = $this->roles($user);

        if ($roles->contains('is_super_admin', true)) {
            return ['*'];
        }

        return $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return Collection<int, Role> */
    public function roles(User $user): Collection
    {
        $user->loadMissing('roles.permissions');

        return $user->roles;
    }

    /** @param list<string> $permissions */
    public function allowsAll(User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        $roles = $user->roles()->with('permissions')->get();

        if ($roles->contains('is_super_admin', true)) {
            return true;
        }

        $grantedPermissions = $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();

        return collect($permissions)->every(
            fn (string $permission): bool => $grantedPermissions->contains($permission)
        );
    }
}
