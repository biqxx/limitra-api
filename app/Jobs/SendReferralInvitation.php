<?php

namespace App\Jobs;

use App\Models\Referral\CustomerReferralInvitation;
use App\Notifications\ReferralInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendReferralInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $invitationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $invitation = CustomerReferralInvitation::query()
            ->with(['referrer:id,username', 'referralCode:id,code'])
            ->find($this->invitationId);
        if (! $invitation || $invitation->status === 'sent' || $invitation->channel !== 'email') {
            return;
        }

        try {
            Notification::route('mail', $invitation->target_ciphertext)
                ->notify(new ReferralInvitationNotification(
                    $invitation->referrer->username,
                    $invitation->referralCode->code,
                    $invitation->message,
                ));
            $invitation->update([
                'status' => 'sent',
                'sent_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
            ]);
        } catch (Throwable $exception) {
            $invitation->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_code' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
