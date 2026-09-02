<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomaticRefundInitiatedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $orderId,
        private readonly string $orderNumber,
        private readonly string $refundReference,
        private readonly string $amount,
        private readonly string $currency,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, 'refund.initiated', ['database', 'mail']);
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
            'event' => 'refund.initiated',
            'title' => 'Refund started',
            'message' => 'Your refund of '.$this->money().' for order '.$this->orderNumber.' has started.',
            'severity' => 'info',
            'action' => [
                'type' => 'open_order',
                'label' => 'View order',
                'url' => $this->orderUrl(),
            ],
            'metadata' => [
                'order_id' => $this->orderId,
                'order_number' => $this->orderNumber,
                'refund_reference' => $this->refundReference,
                'amount' => $this->amount,
                'currency' => strtoupper($this->currency),
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Refund started for order '.$this->orderNumber)
            ->greeting('Hello, '.($notifiable->username ?? 'there').'!')
            ->line('Your payment arrived after the order reservation expired, and the inventory was no longer available.')
            ->line('We have started an automatic refund of '.$this->money().'.')
            ->line('Refund reference: '.$this->refundReference)
            ->action('View order', $this->orderUrl());
    }

    private function money(): string
    {
        return strtoupper($this->currency).' '.number_format((float) $this->amount, 2);
    }

    private function orderUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/orders/'.$this->orderId;
    }
}
