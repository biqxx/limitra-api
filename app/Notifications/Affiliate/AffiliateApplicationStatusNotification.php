<?php

namespace App\Notifications\Affiliate;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateApplicationStatusNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $status,
        private readonly ?string $affiliateCode = null,
        private readonly ?string $notes = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'affiliate.application_status', ['database', 'mail']);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications', 'mail' => 'notifications'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'mail' => (string) config('queue.default')];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $approved = $this->status === 'approved';

        return [
            'event' => 'affiliate.application_status',
            'title' => $approved ? 'Affiliate application approved' : 'Affiliate application update',
            'message' => $approved
                ? "Congratulations! Your affiliate application has been approved. Your affiliate code is {$this->affiliateCode}."
                : 'Your affiliate application was not approved.'.($this->notes ? " Reason: {$this->notes}" : ''),
            'severity' => $approved ? 'success' : 'warning',
            'action' => [
                'type' => 'open_affiliate_portal',
                'label' => $approved ? 'Open affiliate portal' : 'View application',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/affiliate',
            ],
            'metadata' => [
                'status' => $this->status,
                'affiliate_code' => $this->affiliateCode,
                'notes' => $this->notes,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->status === 'approved';
        $mail = (new MailMessage)
            ->subject($approved ? 'Your Limitra Affiliate application is approved!' : 'Update on your Limitra Affiliate application');

        if ($approved) {
            $mail->line('Congratulations! Your Limitra Affiliate application has been approved.')
                ->when($this->affiliateCode, fn (MailMessage $msg) => $msg->line("Your affiliate referral code is: {$this->affiliateCode}"))
                ->action('Go to Affiliate Portal', rtrim((string) config('app.frontend_url'), '/').'/affiliate');
        } else {
            $mail->line('Thank you for your interest in the Limitra Affiliate Program. Unfortunately, your application was not approved at this time.')
                ->when($this->notes, fn (MailMessage $msg) => $msg->line("Notes: {$this->notes}"));
        }

        return $mail;
    }
}

