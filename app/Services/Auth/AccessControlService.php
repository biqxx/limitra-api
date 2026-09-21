<?php

namespace App\Services\Auth;

use App\Enums\StaffInvitationStatus;
use App\Enums\UserStatus;
use App\Exceptions\AccessControlConflictException;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessControlService
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuditEventService $auditEvents,
    ) {}

    /** @param array{name: string, display_name: string, description?: string|null, permissions: list<string>} $attributes */
    public function createRole(User $actor, array $attributes): Role
    {
        return DB::transaction(function () use ($actor, $attributes): Role {
            $permissionNames = $attributes['permissions'];
            $this->assertMayGrantPermissions($actor, $permissionNames);

            $role = Role::query()->create([
                'name' => $attributes['name'],
                'display_name' => $attributes['display_name'],
                'description' => $attributes['description'] ?? null,
            ]);
            $role->permissions()->sync($this->permissionIds($permissionNames));
            $role->load('permissions');

            $this->auditEvents->record(
                action: 'role.created',
                actor: $actor,
                subject: $role,
                after: $this->roleSnapshot($role),
            );

            return $role;
        }, 3);
    }

    /** @param array{name?: string, display_name?: string, description?: string|null, permissions?: list<string>} $attributes */
    public function updateRole(User $actor, Role $role, array $attributes): Role
    {
        return DB::transaction(function () use ($actor, $attributes, $role): Role {
            $lockedRole = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->getKey());
            $this->assertMutable($lockedRole);
            $before = $this->roleSnapshot($lockedRole);

            if (array_key_exists('permissions', $attributes)) {
                $this->assertMayGrantPermissions($actor, $attributes['permissions']);
                $lockedRole->permissions()->sync($this->permissionIds($attributes['permissions']));
                unset($attributes['permissions']);
            }

            $lockedRole->update($attributes);
            $lockedRole->load('permissions');

            $this->auditEvents->record(
                action: 'role.updated',
                actor: $actor,
                subject: $lockedRole,
                before: $before,
                after: $this->roleSnapshot($lockedRole),
            );

            return $lockedRole;
        }, 3);
    }

    public function deleteRole(User $actor, Role $role): void
    {
        DB::transaction(function () use ($actor, $role): void {
            $lockedRole = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->getKey());
            $this->assertMutable($lockedRole);

            if ($lockedRole->users()->exists()) {
                throw new AccessControlConflictException('An assigned role cannot be deleted.');
            }

            if ($lockedRole->staffInvitations()
                ->whereIn('status', [
                    StaffInvitationStatus::Queued->value,
                    StaffInvitationStatus::Sent->value,
                    StaffInvitationStatus::Failed->value,
                ])
                ->where('expires_at', '>', now())
                ->exists()) {
                throw new AccessControlConflictException('A role with active staff invitations cannot be deleted.');
            }

            $before = $this->roleSnapshot($lockedRole);

            $this->auditEvents->record(
                action: 'role.deleted',
                actor: $actor,
                subject: $lockedRole,
                before: $before,
            );

            $lockedRole->delete();
        }, 3);
    }

    public function addMember(User $actor, Role $role, User $user): User
    {
        return DB::transaction(function () use ($actor, $role, $user): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedRole = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->getKey());
            $this->assertMutable($lockedRole);
            $this->assertStaff($lockedUser);
            $this->assertMayDelegateRole($actor, $lockedRole);

            if ($lockedUser->roles()->whereKey($lockedRole->getKey())->exists()) {
                throw new AccessControlConflictException('The user is already assigned to this role.');
            }

            $before = $this->userRoleSnapshot($lockedUser);
            $lockedUser->roles()->attach($lockedRole, [
                'assigned_by' => $actor->getKey(),
                'is_primary' => false,
                'assigned_at' => now(),
            ]);
            $lockedUser->unsetRelation('roles');

            $this->auditEvents->record(
                action: 'role.member_added',
                actor: $actor,
                subject: $lockedUser,
                before: $before,
                after: $this->userRoleSnapshot($lockedUser),
                metadata: ['role_id' => $lockedRole->getKey(), 'role_name' => $lockedRole->name],
            );

            return $lockedUser;
        }, 3);
    }

    public function removeMember(User $actor, Role $role, User $user): void
    {
        DB::transaction(function () use ($actor, $role, $user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedRole = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->getKey());
            $this->assertMutable($lockedRole);
            $this->assertMayDelegateRole($actor, $lockedRole);

            if (! $lockedUser->roles()->whereKey($lockedRole->getKey())->exists()) {
                throw new AccessControlConflictException('The user is not assigned to this role.');
            }

            $before = $this->userRoleSnapshot($lockedUser);
            $lockedUser->roles()->detach($lockedRole);
            $lockedUser->unsetRelation('roles');

            $this->auditEvents->record(
                action: 'role.member_removed',
                actor: $actor,
                subject: $lockedUser,
                before: $before,
                after: $this->userRoleSnapshot($lockedUser),
                metadata: ['role_id' => $lockedRole->getKey(), 'role_name' => $lockedRole->name],
            );
        }, 3);
    }

    public function replaceStaffRole(User $actor, User $user, Role $role): User
    {
        return DB::transaction(function () use ($actor, $role, $user): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $lockedRole = Role::query()->with('permissions')->lockForUpdate()->findOrFail($role->getKey());
            $this->assertMutable($lockedRole);
            $this->assertStaff($lockedUser);
            $this->assertMayDelegateRole($actor, $lockedRole);
            $before = $this->userRoleSnapshot($lockedUser);

            $customRoleIds = $lockedUser->roles()
                ->where('is_system', false)
                ->pluck('roles.id');
            $lockedUser->roles()->detach($customRoleIds);
            $lockedUser->roles()->attach($lockedRole, [
                'assigned_by' => $actor->getKey(),
                'is_primary' => false,
                'assigned_at' => now(),
            ]);
            $lockedUser->unsetRelation('roles');

            $this->auditEvents->record(
                action: 'user.operational_role_changed',
                actor: $actor,
                subject: $lockedUser,
                before: $before,
                after: $this->userRoleSnapshot($lockedUser),
                metadata: ['role_id' => $lockedRole->getKey(), 'role_name' => $lockedRole->name],
            );

            return $lockedUser;
        }, 3);
    }

    public function updateLegacyRole(User $actor, User $user, string $role): User
    {
        $this->assertSuperAdmin($actor);

        return DB::transaction(function () use ($actor, $role, $user): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $superAdminRole = $this->lockSuperAdminRole();
            $this->assertSuperAdmin($actor);

            if ($lockedUser->role === $role) {
                return $lockedUser->load('roles.permissions');
            }

            if ($lockedUser->role === 'admin' && $role !== 'admin') {
                $this->assertNotLastAdministrator($lockedUser, $superAdminRole);
            }

            $before = $this->userRoleSnapshot($lockedUser);
            $lockedUser->update(['role' => $role]);

            if ($role !== 'staff' && $role !== 'admin') {
                $customRoleIds = $lockedUser->roles()
                    ->where('is_system', false)
                    ->pluck('roles.id');
                $lockedUser->roles()->detach($customRoleIds);
            }

            $lockedUser->unsetRelation('roles');

            $this->auditEvents->record(
                action: 'user.role_changed',
                actor: $actor,
                subject: $lockedUser,
                before: $before,
                after: $this->userRoleSnapshot($lockedUser),
            );

            return $lockedUser;
        }, 3);
    }

    /** @param list<string> $permissionNames */
    private function assertMayGrantPermissions(User $actor, array $permissionNames): void
    {
        if ($this->permissions->isSuperAdmin($actor)) {
            return;
        }

        $missingPermissions = array_diff($permissionNames, $this->permissions->resolveFresh($actor));

        if ($missingPermissions !== []) {
            throw new AuthorizationException('You cannot grant permissions you do not hold.');
        }
    }

    private function assertMayDelegateRole(User $actor, Role $role): void
    {
        $this->assertMayGrantPermissions($actor, $role->permissions->pluck('name')->all());
    }

    private function assertMutable(Role $role): void
    {
        if ($role->is_system || $role->is_super_admin) {
            throw new AccessControlConflictException('Protected system roles cannot be modified.');
        }
    }

    private function assertStaff(User $user): void
    {
        if ($user->role !== 'staff') {
            throw ValidationException::withMessages([
                'user_id' => ['Custom operational roles may only be assigned to staff users.'],
            ]);
        }
    }

    private function assertSuperAdmin(User $user): void
    {
        if (! $this->permissions->isSuperAdmin($user)) {
            throw new AuthorizationException('Only a super administrator may perform this action.');
        }
    }

    private function lockSuperAdminRole(): Role
    {
        return Role::query()->where('is_super_admin', true)->lockForUpdate()->firstOrFail();
    }

    private function assertNotLastAdministrator(User $user, Role $superAdminRole): void
    {
        if (! $superAdminRole->users()->whereKey($user->getKey())->exists()) {
            return;
        }

        if ($user->status === UserStatus::Suspended
            && ($user->suspended_until === null || $user->suspended_until->isFuture())) {
            return;
        }

        $administratorCount = $superAdminRole->users()
            ->where(function ($query): void {
                $query->where('status', UserStatus::Active->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', UserStatus::Suspended->value)
                            ->whereNotNull('suspended_until')
                            ->where('suspended_until', '<=', now());
                    });
            })
            ->lockForUpdate()
            ->get(['users.id'])
            ->count();

        if ($administratorCount <= 1) {
            throw new AccessControlConflictException('The last administrator cannot be removed.');
        }
    }

    /** @param list<string> $permissionNames */
    private function permissionIds(array $permissionNames): array
    {
        return Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function roleSnapshot(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function userRoleSnapshot(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'legacy_role' => $user->role,
            'roles' => $user->roles()->orderBy('roles.name')->pluck('roles.name')->all(),
        ];
    }
}
