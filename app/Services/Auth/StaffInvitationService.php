<?php

namespace App\Services\Auth;

use App\Enums\StaffInvitationStatus;
use App\Exceptions\AccessControlConflictException;
use App\Jobs\SendStaffInvitation;
use App\Models\User;
use App\Models\User\Profile;
use App\Models\User\Role;
use App\Models\User\StaffInvitation;
use App\Services\Admin\AuditEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffInvitationService
{
    private const EXPIRY_HOURS = 48;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuditEventService $auditEvents,
    ) {}

    /** @param array{name: string, email: string, role_id?: int|null} $attributes */
    public function create(User $actor, array $attributes): StaffInvitation
    {
        $email = Str::lower(trim($attributes['email']));
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $attributes, $email, $token): StaffInvitation {
            $role = $this->lockRole($attributes['role_id'] ?? null);

            if (($attributes['role_id'] ?? null) !== null && ! $role) {
                throw ValidationException::withMessages(['role_id' => ['The selected role is unavailable.']]);
            }

            $this->assertMayInvite($actor, $role);

            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['An account already exists for this email address.'],
                ]);
            }

            $emailHash = $this->hash($email);
            $invitation = StaffInvitation::query()->where('email_hash', $emailHash)->lockForUpdate()->first();

            if ($invitation && $this->isOpen($invitation)) {
                throw new AccessControlConflictException('An active invitation already exists for this email address.');
            }

            $invitationData = [
                'public_id' => $invitation?->public_id ?? (string) Str::uuid(),
                'email_hash' => $emailHash,
                'email_ciphertext' => $email,
                'email_masked' => $this->maskEmail($email),
                'name' => trim($attributes['name']),
                'role_id' => $role?->getKey(),
                'role_name_snapshot' => $role?->name,
                'invited_by' => $actor->getKey(),
                'status' => StaffInvitationStatus::Queued,
                'token_hash' => $this->hash($token),
                'token_ciphertext' => $token,
                'delivery_version' => ($invitation?->delivery_version ?? 0) + 1,
                'expires_at' => now()->addHours(self::EXPIRY_HOURS),
                'sent_at' => null,
                'accepted_at' => null,
                'revoked_at' => null,
                'failed_at' => null,
                'accepted_user_id' => null,
                'failure_code' => null,
            ];

            if ($invitation) {
                $invitation->forceFill($invitationData)->save();
            } else {
                $invitation = StaffInvitation::query()->create($invitationData);
            }

            $this->auditEvents->record(
                action: 'staff.invitation_created',
                actor: $actor,
                subject: $invitation,
                after: $this->snapshot($invitation),
            );

            return $invitation;
        }, 3);

        SendStaffInvitation::dispatch($invitation->getKey(), $invitation->delivery_version)->afterCommit();

        return $invitation->load('role');
    }

    public function resend(User $actor, StaffInvitation $invitation): StaffInvitation
    {
        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $invitation, $token): StaffInvitation {
            $lockedInvitation = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            $role = $this->lockRole($lockedInvitation->role_id);

            if ($lockedInvitation->role_name_snapshot && ! $role) {
                throw new AccessControlConflictException('The invitation role is no longer available.');
            }

            $this->assertMayInvite($actor, $role);

            if ($lockedInvitation->status === StaffInvitationStatus::Accepted
                || $lockedInvitation->status === StaffInvitationStatus::Revoked) {
                throw new AccessControlConflictException('This invitation can no longer be resent.');
            }

            $before = $this->snapshot($lockedInvitation);
            $lockedInvitation->forceFill([
                'status' => StaffInvitationStatus::Queued,
                'token_hash' => $this->hash($token),
                'token_ciphertext' => $token,
                'delivery_version' => $lockedInvitation->delivery_version + 1,
                'expires_at' => now()->addHours(self::EXPIRY_HOURS),
                'sent_at' => null,
                'failed_at' => null,
                'failure_code' => null,
            ])->save();

            $this->auditEvents->record(
                action: 'staff.invitation_resent',
                actor: $actor,
                subject: $lockedInvitation,
                before: $before,
                after: $this->snapshot($lockedInvitation),
            );

            return $lockedInvitation;
        }, 3);

        SendStaffInvitation::dispatch($invitation->getKey(), $invitation->delivery_version)->afterCommit();

        return $invitation->load('role');
    }

    public function revoke(User $actor, StaffInvitation $invitation, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $invitation, $reason): void {
            $lockedInvitation = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());
            $this->assertMayInvite($actor, $this->lockRole($lockedInvitation->role_id));

            if ($lockedInvitation->status === StaffInvitationStatus::Accepted
                || $lockedInvitation->status === StaffInvitationStatus::Revoked) {
                throw new AccessControlConflictException('This invitation can no longer be revoked.');
            }

            $before = $this->snapshot($lockedInvitation);
            $lockedInvitation->forceFill([
                'status' => StaffInvitationStatus::Revoked,
                'token_hash' => null,
                'token_ciphertext' => null,
                'revoked_at' => now(),
            ])->save();

            $this->auditEvents->record(
                action: 'staff.invitation_revoked',
                actor: $actor,
                subject: $lockedInvitation,
                reason: $reason,
                before: $before,
                after: $this->snapshot($lockedInvitation),
            );
        }, 3);
    }

    /** @param array{token: string, username: string, password: string, phone?: string|null} $attributes */
    public function accept(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $invitation = StaffInvitation::query()
                ->where('token_hash', $this->hash($attributes['token']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $this->isOpen($invitation)) {
                throw ValidationException::withMessages([
                    'token' => ['The invitation is invalid or has expired.'],
                ]);
            }

            $inviter = User::query()->lockForUpdate()->find($invitation->invited_by);
            $role = $this->lockRole($invitation->role_id);

            if (! $inviter || ($invitation->role_name_snapshot && ! $role)) {
                throw ValidationException::withMessages([
                    'token' => ['The invitation is no longer valid.'],
                ]);
            }

            try {
                $this->assertMayInvite($inviter, $role);
            } catch (AuthorizationException|ValidationException) {
                throw ValidationException::withMessages([
                    'token' => ['The invitation is no longer valid.'],
                ]);
            }
            $email = $invitation->email_ciphertext;

            if (User::query()->where('email', $email)->exists()) {
                throw new AccessControlConflictException('An account already exists for this invitation.');
            }

            $nameParts = preg_split('/\s+/', trim($invitation->name), 2);
            $user = User::query()->create([
                'username' => $attributes['username'],
                'email' => $email,
                'password' => $attributes['password'],
                'role' => 'staff',
                'email_verified_at' => now(),
            ]);
            Profile::query()->create([
                'user_id' => $user->getKey(),
                'first_name' => $nameParts[0],
                'last_name' => $nameParts[1] ?? null,
                'phone' => $attributes['phone'] ?? null,
            ]);

            if ($role) {
                $user->roles()->attach($role, [
                    'assigned_by' => $inviter->getKey(),
                    'is_primary' => false,
                    'assigned_at' => now(),
                ]);
            }

            $invitation->forceFill([
                'status' => StaffInvitationStatus::Accepted,
                'accepted_at' => now(),
                'accepted_user_id' => $user->getKey(),
                'token_hash' => null,
                'token_ciphertext' => null,
            ])->save();

            $this->auditEvents->record(
                action: 'staff.invitation_accepted',
                actor: $user,
                subject: $user,
                after: [
                    'legacy_role' => 'staff',
                    'operational_role' => $role?->name,
                ],
                metadata: ['invitation_id' => $invitation->public_id],
            );

            return $user->load(['profile', 'roles.permissions']);
        }, 3);
    }

    private function lockRole(?int $roleId): ?Role
    {
        return $roleId === null
            ? null
            : Role::query()->with('permissions')->lockForUpdate()->find($roleId);
    }

    private function assertMayInvite(User $actor, ?Role $role): void
    {
        if (! $this->permissions->allowsAll($actor, ['roles.manage'])) {
            throw new AuthorizationException('You are not authorized to manage staff invitations.');
        }

        if (! $role) {
            return;
        }

        if ($role->is_system || $role->is_super_admin) {
            throw ValidationException::withMessages([
                'role_id' => ['Invitations may only assign custom operational roles.'],
            ]);
        }

        if ($this->permissions->isSuperAdmin($actor)) {
            return;
        }

        $missingPermissions = array_diff(
            $role->permissions->pluck('name')->all(),
            $this->permissions->resolveFresh($actor),
        );

        if ($missingPermissions !== []) {
            throw new AuthorizationException('You cannot assign a role with permissions you do not hold.');
        }
    }

    private function isOpen(StaffInvitation $invitation): bool
    {
        return in_array($invitation->status, [StaffInvitationStatus::Queued, StaffInvitationStatus::Sent, StaffInvitationStatus::Failed], true)
            && $invitation->expires_at->isFuture()
            && $invitation->revoked_at === null
            && $invitation->accepted_at === null;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    /** @return array<string, mixed> */
    private function snapshot(StaffInvitation $invitation): array
    {
        return [
            'public_id' => $invitation->public_id,
            'email_masked' => $invitation->email_masked,
            'role' => $invitation->role_name_snapshot,
            'status' => $invitation->status->value,
            'delivery_version' => $invitation->delivery_version,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
