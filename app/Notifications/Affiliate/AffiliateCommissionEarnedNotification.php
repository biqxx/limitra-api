<?php

namespace App\Notifications\Affiliate;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateCommissionEarnedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $orderNumber,
        private readonly float $amount,
        private readonly string $currency = 'NGN'
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'affiliate.commission_earned', ['database', 'mail']);
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
        $formatted = number_format($this->amount, 2, '.', ',');

        return [
            'event' => 'affiliate.commission_earned',
            'title' => 'New affiliate commission earned',
            'message' => "You earned {$formatted} {$this->currency} commission from order {$this->orderNumber}.",
            'severity' => 'success',
            'action' => [
                'type' => 'open_affiliate_portal',
                'label' => 'View commissions',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/affiliate/commissions',
            ],
            'metadata' => [
                'order_number' => $this->orderNumber,
                'amount' => $this->amount,
                'currency' => $this->currency,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = number_format($this->amount, 2, '.', ',');

        return (new MailMessage)
            ->subject('New affiliate commission earned!')
            ->line("You earned {$formatted} {$this->currency} commission from order {$this->orderNumber}.")
            ->action('View commissions', rtrim((string) config('app.frontend_url'), '/').'/affiliate/commissions');
    }
}
