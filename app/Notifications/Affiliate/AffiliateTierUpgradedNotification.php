<?php

namespace App\Notifications\Affiliate;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateTierUpgradedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $tier,
        private readonly float $commissionRate
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'affiliate.tier_upgraded', ['database', 'mail']);
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
        return [
            'event' => 'affiliate.tier_upgraded',
            'title' => 'Affiliate Tier Upgraded!',
            'message' => "Congratulations! You have been upgraded to {$this->tier} tier with a {$this->commissionRate}% commission rate.",
            'severity' => 'success',
            'action' => [
                'type' => 'open_affiliate_dashboard',
                'label' => 'View dashboard',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/affiliate/dashboard',
            ],
            'metadata' => [
                'tier' => $this->tier,
                'commission_rate' => $this->commissionRate,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Congratulations! Affiliate tier upgraded')
            ->line("Congratulations! You have been upgraded to the {$this->tier} tier with a {$this->commissionRate}% commission rate.")
            ->action('View dashboard', rtrim((string) config('app.frontend_url'), '/').'/affiliate/dashboard');
    }
}
