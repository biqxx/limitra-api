<?php

namespace App\Jobs;

use App\Enums\StaffInvitationStatus;
use App\Models\User\StaffInvitation;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendStaffInvitation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900, 1800];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $invitationId,
        public readonly int $deliveryVersion,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $invitation = StaffInvitation::query()->find($this->invitationId);

        if (! $invitation
            || $invitation->delivery_version !== $this->deliveryVersion
            || ! in_array($invitation->status, [StaffInvitationStatus::Queued, StaffInvitationStatus::Failed], true)
            || $invitation->expires_at->isPast()
            || ! $invitation->token_ciphertext) {
            return;
        }

        Notification::route('mail', $invitation->email_ciphertext)
            ->notify(new StaffInvitationNotification(
                $invitation->name,
                $invitation->token_ciphertext,
                $invitation->role_name_snapshot,
                $invitation->expires_at,
            ));

        StaffInvitation::query()
            ->whereKey($invitation->getKey())
            ->where('delivery_version', $this->deliveryVersion)
            ->update([
                'status' => StaffInvitationStatus::Sent->value,
                'token_ciphertext' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
            ]);
    }

    public function uniqueId(): string
    {
        return $this->invitationId.':'.$this->deliveryVersion;
    }

    public function failed(?Throwable $exception): void
    {
        StaffInvitation::query()
            ->whereKey($this->invitationId)
            ->where('delivery_version', $this->deliveryVersion)
            ->whereIn('status', [StaffInvitationStatus::Queued->value, StaffInvitationStatus::Failed->value])
            ->update([
                'status' => StaffInvitationStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => $exception === null ? null : $exception::class,
            ]);
    }
}
