<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Exceptions\AccessControlConflictException;
use App\Models\Order\Order;
use App\Models\Referral\CustomerReferralCode;
use App\Models\Social\SocialAccount;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserDeactivationService
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuthSessionManager $sessions,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function deactivate(User $actor, User $user, string $reason): void
    {
        [$sessionIds, $avatarPaths] = DB::transaction(
            function () use ($actor, $reason, $user): array {
                $lockedUsers = User::query()
                    ->whereKey([$actor->getKey(), $user->getKey()])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedActor = $lockedUsers->get($actor->getKey());
                $lockedUser = $lockedUsers->get($user->getKey());

                if (! $lockedActor || ! $lockedUser) {
                    throw new AuthorizationException('The account cannot be deactivated.');
                }

                $this->assertSuperAdmin($lockedActor);

                if ($lockedUser->is($lockedActor)) {
                    throw new AccessControlConflictException('You cannot deactivate your own account.');
                }

                $superAdminRole = Role::query()
                    ->where('is_super_admin', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUser->role === 'admin') {
                    $this->assertNotLastAdministrator($lockedUser, $superAdminRole);
                }

                $before = $this->snapshot($lockedUser);
                $now = now();
                $anonymousEmail = "deactivated+{$lockedUser->getKey()}@users.invalid";
                $avatarPaths = $this->anonymizeOwnedData($lockedUser, $anonymousEmail);
                $sessionIds = $this->sessions->revokeAllInDatabase($lockedUser);

                $lockedUser->authSessions()->update([
                    'device_name' => 'Deactivated session',
                    'ip_address' => null,
                    'user_agent' => null,
                    'revoked_at' => $now,
                ]);
                DB::table('password_reset_tokens')
                    ->where('user_id', $lockedUser->getKey())
                    ->orWhere('email', $lockedUser->email)
                    ->delete();

                $lockedUser->forceFill([
                    'username' => "deactivated_{$lockedUser->getKey()}",
                    'email' => $anonymousEmail,
                    'pending_email' => null,
                    'email_change_otp' => null,
                    'email_change_expires_at' => null,
                    'email_change_attempts' => 0,
                    'email_verification_otp' => null,
                    'email_verification_expires_at' => null,
                    'email_verification_sent_at' => null,
                    'email_verification_attempts' => 0,
                    'email_verified_at' => null,
                    'password' => Str::random(64),
                    'remember_token' => null,
                    'referred_by' => null,
                    'role' => 'user',
                    'status' => UserStatus::Deactivated,
                    'suspended_at' => null,
                    'suspended_until' => null,
                    'suspension_reason' => null,
                    'suspended_by' => null,
                    'deactivated_at' => $now,
                    'deactivation_reason' => $reason,
                    'deactivated_by' => $lockedActor->getKey(),
                ])->save();
                $lockedUser->roles()->detach();
                $lockedUser->unsetRelation('roles');

                $this->auditEvents->record(
                    action: 'user.deactivated',
                    actor: $lockedActor,
                    subject: $lockedUser,
                    reason: $reason,
                    before: $before,
                    after: $this->snapshot($lockedUser),
                );

                $lockedUser->delete();

                return [$sessionIds, $avatarPaths];
            },
            3,
        );

        $this->sessions->forgetRevokedSessions($sessionIds);

        if ($avatarPaths !== []) {
            Storage::disk('public')->delete($avatarPaths);
        }
    }

    /** @return list<string> */
    private function anonymizeOwnedData(User $user, string $anonymousEmail): array
    {
        $avatarPaths = collect([$user->profile()->value('avatar')])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $user->profile()->update([
            'first_name' => 'Deactivated',
            'middle_name' => null,
            'last_name' => 'User',
            'avatar' => null,
            'phone' => null,
            'birthday' => null,
            'gender' => null,
            'subscribe_to_newsletter' => false,
        ]);
        $user->addresses()->update([
            'label' => null,
            'recipient_name' => 'Deactivated User',
            'phone' => 'redacted',
            'line1' => 'Redacted',
            'line2' => null,
            'city' => 'Redacted',
            'landmark' => null,
            'state' => 'Redacted',
            'postal_code' => null,
            'is_default' => false,
        ]);
        $user->savedCards()->delete();
        $user->notifications()->delete();

        Order::withTrashed()
            ->where('user_id', $user->getKey())
            ->update([
                'contact_email' => $anonymousEmail,
                'notes' => null,
                'shipping_address' => json_encode([
                    'recipient_name' => 'Deactivated User',
                    'phone' => null,
                    'line1' => 'Redacted',
                    'line2' => null,
                    'city' => null,
                    'landmark' => null,
                    'state' => null,
                    'country' => null,
                    'postal_code' => null,
                ], JSON_THROW_ON_ERROR),
            ]);
        SupportTicket::query()
            ->where('user_id', $user->getKey())
            ->update([
                'contact_name' => 'Deactivated User',
                'contact_email' => $anonymousEmail,
            ]);
        SupportTicket::query()
            ->where('assigned_to', $user->getKey())
            ->update(['assigned_to' => null]);

        SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (SocialAccount $account) use ($user): void {
                $account->update([
                    'user_id' => null,
                    'platform_sender_id' => "deactivated:{$user->getKey()}:{$account->getKey()}",
                    'username' => null,
                    'metadata' => null,
                ]);
            });

        CustomerReferralCode::query()
            ->where('user_id', $user->getKey())
            ->update(['active' => false]);

        return $avatarPaths;
    }

    private function assertSuperAdmin(User $user): void
    {
        if (! $this->permissions->isSuperAdmin($user)) {
            throw new AuthorizationException('Only a super administrator may deactivate accounts.');
        }
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
            throw new AccessControlConflictException('The last active administrator cannot be deactivated.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'legacy_role' => $user->role,
            'status' => $user->status->value,
            'roles' => $user->roles()->orderBy('roles.name')->pluck('roles.name')->all(),
        ];
    }
}
