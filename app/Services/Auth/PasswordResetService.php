<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Jobs\SendPasswordResetOtp;
use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use App\Services\Admin\AuditEventService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class PasswordResetService
{
    private const EXPIRY_MINUTES = 15;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function issue(User $user, ?User $actor = null, ?string $reason = null): void
    {
        $otp = (string) random_int(100_000, 999_999);

        [$userId, $actorId, $deliveryVersion] = DB::transaction(
            function () use ($actor, $otp, $reason, $user): array {
                $userIds = collect([$actor?->getKey(), $user->getKey()])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();
                $lockedUsers = User::query()
                    ->whereKey($userIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedUser = $lockedUsers->get($user->getKey());
                $lockedActor = $actor ? $lockedUsers->get($actor->getKey()) : null;

                if (! $lockedUser || ($actor && ! $lockedActor)) {
                    throw new AuthorizationException('The password reset cannot be requested.');
                }

                if ($lockedActor) {
                    $this->authorizeAdminRequest($lockedActor, $lockedUser);
                }

                $existing = DB::table('password_reset_tokens')
                    ->where('email', $lockedUser->email)
                    ->lockForUpdate()
                    ->first();
                $deliveryVersion = ((int) ($existing?->delivery_version ?? 0)) + 1;

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $lockedUser->email],
                    [
                        'user_id' => $lockedUser->getKey(),
                        'token' => Hash::make($otp),
                        'delivery_secret' => Crypt::encryptString($otp),
                        'delivery_version' => $deliveryVersion,
                        'requested_by' => $lockedActor?->getKey(),
                        'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
                        'sent_at' => null,
                        'failed_at' => null,
                        'failure_code' => null,
                        'created_at' => now(),
                    ],
                );

                if ($lockedActor) {
                    $this->auditEvents->record(
                        action: 'user.password_reset_requested',
                        actor: $lockedActor,
                        subject: $lockedUser,
                        reason: $reason,
                        metadata: ['delivery_channel' => 'email'],
                    );
                }

                return [$lockedUser->getKey(), $lockedActor?->getKey(), $deliveryVersion];
            },
            3,
        );

        SendPasswordResetOtp::dispatch($userId, $actorId, $deliveryVersion)->afterCommit();
    }

    public function deliver(int $userId, ?int $actorId, int $deliveryVersion): void
    {
        $record = DB::table('password_reset_tokens')
            ->where('user_id', $userId)
            ->where('delivery_version', $deliveryVersion)
            ->first();

        if (! $record || ! $record->delivery_secret || now()->isAfter($record->expires_at)) {
            return;
        }

        $user = User::query()->find($userId);
        $actor = $actorId === null ? null : User::query()->find($actorId);

        if (! $user || $user->email !== $record->email || ($actorId !== null && ! $actor)) {
            $this->cancelDelivery($userId, $deliveryVersion, 'account_unavailable');

            return;
        }

        if ($actor) {
            try {
                $this->authorizeAdminRequest($actor, $user);
            } catch (AuthorizationException) {
                $this->cancelDelivery($userId, $deliveryVersion, 'authorization_revoked');

                return;
            }
        }

        $user->notify(new PasswordResetOtpNotification(Crypt::decryptString($record->delivery_secret)));

        DB::table('password_reset_tokens')
            ->where('user_id', $userId)
            ->where('delivery_version', $deliveryVersion)
            ->update([
                'delivery_secret' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
            ]);
    }

    public function markDeliveryFailed(int $userId, int $deliveryVersion, ?Throwable $exception): void
    {
        DB::table('password_reset_tokens')
            ->where('user_id', $userId)
            ->where('delivery_version', $deliveryVersion)
            ->update([
                'delivery_secret' => null,
                'failed_at' => now(),
                'failure_code' => $exception === null ? null : $exception::class,
            ]);
    }

    public function authorizeAdminRequest(User $actor, User $target): void
    {
        if ($actor->status === UserStatus::Suspended
            && ($actor->suspended_until === null || $actor->suspended_until->isFuture())) {
            throw new AuthorizationException('A suspended account cannot request password resets.');
        }

        if (! $this->permissions->allowsAll($actor, ['customers.update'])) {
            throw new AuthorizationException('You are not authorized to request password resets.');
        }

        if ($this->permissions->isSuperAdmin($target) && ! $this->permissions->isSuperAdmin($actor)) {
            throw new AuthorizationException('Only a super administrator may reset an administrator password.');
        }

        if ($target->role === 'staff' && ! $this->permissions->allowsAll($actor, ['roles.manage'])) {
            throw new AuthorizationException('Staff password resets require role management permission.');
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

    private function cancelDelivery(int $userId, int $deliveryVersion, string $reason): void
    {
        DB::table('password_reset_tokens')
            ->where('user_id', $userId)
            ->where('delivery_version', $deliveryVersion)
            ->update([
                'delivery_secret' => null,
                'failed_at' => now(),
                'failure_code' => $reason,
            ]);
    }
}
