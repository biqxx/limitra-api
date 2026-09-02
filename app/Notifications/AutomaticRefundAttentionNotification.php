<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomaticRefundAttentionNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $orderId,
        private readonly string $orderNumber,
        private readonly string $refundReference,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, 'refund.needs_attention', ['database', 'mail']);
    }

    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            'mail' => 'notifications',
        ];
    }

    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => (string) config('queue.default'),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'refund.needs_attention',
            'title' => 'Refund needs attention',
            'message' => 'Your refund for order '.$this->orderNumber.' needs manual review. Our support team has been alerted.',
            'severity' => 'warning',
            'action' => [
                'type' => 'open_order',
                'label' => 'View order',
                'url' => $this->orderUrl(),
            ],
            'metadata' => [
                'order_id' => $this->orderId,
                'order_number' => $this->orderNumber,
                'refund_reference' => $this->refundReference,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Refund update for order '.$this->orderNumber)
            ->greeting('Hello, '.($notifiable->username ?? 'there').'!')
            ->line('We could not complete your automatic refund and our support team has been alerted.')
            ->line('Refund reference: '.$this->refundReference)
            ->line('You do not need to submit another payment or refund request.')
            ->action('View order', $this->orderUrl());
    }

    private function orderUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/orders/'.$this->orderId;
    }
}
