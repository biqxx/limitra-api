<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomaticRefundProcessedNotification extends Notification implements ShouldQueue
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
        return $this->preferredChannels($notifiable, 'refund.processed', ['database', 'mail']);
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
            'event' => 'refund.processed',
            'title' => 'Refund completed',
            'message' => 'Your refund of '.$this->money().' for order '.$this->orderNumber.' has been processed.',
            'severity' => 'success',
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
            ->subject('Refund completed for order '.$this->orderNumber)
            ->greeting('Hello, '.($notifiable->username ?? 'there').'!')
            ->line('Your automatic refund of '.$this->money().' has been processed.')
            ->line('Refund reference: '.$this->refundReference)
            ->line('Your bank or card provider may need additional time to display the credit.')
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
