<?php

namespace App\Notifications\Affiliate;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateWithdrawalPaidNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $reference,
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $providerReference
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'affiliate.withdrawal_paid', ['database', 'mail']);
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
            'event' => 'affiliate.withdrawal_paid',
            'title' => 'Affiliate withdrawal paid',
            'message' => "Your withdrawal payout of {$formatted} {$this->currency} (ref: {$this->reference}) has been sent.",
            'severity' => 'success',
            'action' => [
                'type' => 'open_affiliate_withdrawals',
                'label' => 'View withdrawals',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/affiliate/withdrawals',
            ],
            'metadata' => [
                'reference' => $this->reference,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'provider_reference' => $this->providerReference,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = number_format($this->amount, 2, '.', ',');

        return (new MailMessage)
            ->subject('Affiliate withdrawal processed and paid')
            ->line("Your withdrawal payout of {$formatted} {$this->currency} (Reference: {$this->reference}) has been completed.")
            ->line("Bank transfer reference: {$this->providerReference}")
            ->action('View withdrawals', rtrim((string) config('app.frontend_url'), '/').'/affiliate/withdrawals');
    }
}
