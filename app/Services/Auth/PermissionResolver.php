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
        return $this->resolveRoles($this->roles($user));
    }

    /** @return list<string> */
    public function resolveFresh(User $user): array
    {
        return $this->resolveRoles($user->roles()->with('permissions')->get());
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->roles()->where('is_super_admin', true)->exists();
    }

    /** @param Collection<int, Role> $roles */
    private function resolveRoles(Collection $roles): array
    {
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

        $grantedPermissions = $this->resolveFresh($user);

        if ($grantedPermissions === ['*']) {
            return true;
        }

        return collect($permissions)->every(
            fn (string $permission): bool => in_array($permission, $grantedPermissions, true)
        );
    }
}
