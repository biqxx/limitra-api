<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardEarnedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly int $amountMinor,
        private readonly string $currency,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'referral.reward_earned', ['database', 'mail']);
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
            'event' => 'referral.reward_earned',
            'title' => 'Referral reward earned',
            'message' => "{$this->formattedAmount()} {$this->currency} was added to your Lim Cash balance.",
            'severity' => 'success',
            'action' => [
                'type' => 'open_wallet',
                'label' => 'View wallet',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/account/wallet',
            ],
            'metadata' => [
                'amount_minor' => $this->amountMinor,
                'currency' => $this->currency,
                'balance_type' => 'lim_cash',
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You earned a referral reward')
            ->line("{$this->formattedAmount()} {$this->currency} was added to your Lim Cash balance.")
            ->action('View wallet', rtrim((string) config('app.frontend_url'), '/').'/account/wallet');
    }

    private function formattedAmount(): string
    {
        return number_format($this->amountMinor / 100, 2, '.', ',');
    }
}
