<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Exceptions\AccessControlConflictException;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UserStatusService
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuthSessionManager $sessions,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function suspend(User $actor, User $user, string $reason, ?CarbonInterface $until = null): User
    {
        return $this->transition($actor, $user, UserStatus::Suspended, $reason, $until);
    }

    public function restore(User $actor, User $user, ?string $note = null): User
    {
        return $this->transition($actor, $user, UserStatus::Active, $note);
    }

    public function isSuspended(User $user): bool
    {
        $user = User::query()->find($user->getKey()) ?? $user;

        if ($user->status !== UserStatus::Suspended) {
            return false;
        }

        if ($user->suspended_until?->isPast()) {
            $this->restoreExpired($user);

            return false;
        }

        return true;
    }

    private function transition(
        User $actor,
        User $user,
        UserStatus $status,
        ?string $reason,
        ?CarbonInterface $until = null,
    ): User {
        $sessionIds = [];

        $user = DB::transaction(function () use ($actor, $reason, $status, $until, $user, &$sessionIds): User {
            $lockedUsers = User::query()
                ->whereKey([$actor->getKey(), $user->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedActor = $lockedUsers->get($actor->getKey());
            $lockedUser = $lockedUsers->get($user->getKey());

            if (! $lockedActor || ! $lockedUser) {
                throw new AuthorizationException('The account status cannot be changed.');
            }

            $this->authorizeTarget($lockedActor, $lockedUser);

            if ($status === UserStatus::Suspended && $lockedActor->is($lockedUser)) {
                throw new AccessControlConflictException('You cannot suspend your own account.');
            }

            if ($lockedUser->status === $status) {
                return $lockedUser->load(['profile', 'roles.permissions']);
            }

            if ($status === UserStatus::Suspended) {
                $this->assertNotLastActiveAdministrator($lockedUser);
            }

            $before = $this->snapshot($lockedUser);
            $lockedUser->forceFill($status === UserStatus::Suspended ? [
                'status' => UserStatus::Suspended,
                'suspended_at' => now(),
                'suspended_until' => $until,
                'suspension_reason' => $reason,
                'suspended_by' => $lockedActor->getKey(),
            ] : [
                'status' => UserStatus::Active,
                'suspended_at' => null,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_by' => null,
            ])->save();

            if ($status === UserStatus::Suspended) {
                $sessionIds = $this->sessions->revokeAllInDatabase($lockedUser);
            }

            $this->auditEvents->record(
                action: $status === UserStatus::Suspended ? 'user.suspended' : 'user.restored',
                actor: $lockedActor,
                subject: $lockedUser,
                reason: $reason,
                before: $before,
                after: $this->snapshot($lockedUser),
            );

            return $lockedUser->load(['profile', 'roles.permissions']);
        }, 3);

        $this->sessions->forgetRevokedSessions($sessionIds);

        return $user;
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        if (! $this->permissions->allowsAll($actor, ['customers.update'])) {
            throw new AuthorizationException('You are not authorized to update account status.');
        }

        if ($target->role === 'admin' && ! $this->permissions->isSuperAdmin($actor)) {
            throw new AuthorizationException('Only a super administrator may update an administrator.');
        }

        if ($target->role === 'staff' && ! $this->permissions->allowsAll($actor, ['roles.manage'])) {
            throw new AuthorizationException('Staff account status requires role management permission.');
        }

        if ($target->role === 'staff' && ! $this->permissions->isSuperAdmin($actor)) {
            $missingPermissions = array_diff(
                $this->permissions->resolveFresh($target),
                $this->permissions->resolveFresh($actor),
            );

            if ($missingPermissions !== []) {
                throw new AuthorizationException('You cannot manage a staff account with higher privileges.');
            }
        }
    }

    private function assertNotLastActiveAdministrator(User $user): void
    {
        $superAdminRole = Role::query()->where('is_super_admin', true)->lockForUpdate()->firstOrFail();

        if (! $superAdminRole->users()->whereKey($user->getKey())->exists()) {
            return;
        }

        $activeAdministrators = $superAdminRole->users()
            ->where(function ($query): void {
                $query->where('status', UserStatus::Active->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', UserStatus::Suspended->value)
                            ->whereNotNull('suspended_until')
                            ->where('suspended_until', '<=', now());
                    });
            })
            ->lockForUpdate()
            ->count();

        if ($activeAdministrators <= 1) {
            throw new AccessControlConflictException('The last active administrator cannot be suspended.');
        }
    }

    private function restoreExpired(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser
                || $lockedUser->status !== UserStatus::Suspended
                || ! $lockedUser->suspended_until?->isPast()) {
                return;
            }

            $before = $this->snapshot($lockedUser);
            $lockedUser->forceFill([
                'status' => UserStatus::Active,
                'suspended_at' => null,
                'suspended_until' => null,
                'suspension_reason' => null,
                'suspended_by' => null,
            ])->save();

            $this->auditEvents->record(
                action: 'user.restored_automatically',
                subject: $lockedUser,
                reason: 'Timed suspension expired.',
                before: $before,
                after: $this->snapshot($lockedUser),
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'status' => $user->status->value,
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'suspended_until' => $user->suspended_until?->toIso8601String(),
            'suspended_by' => $user->suspended_by,
        ];
    }
}
