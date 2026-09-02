<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryReservationExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $orderId,
        private readonly string $orderNumber,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
            'event' => 'order.inventory_reservation_expired',
            'title' => 'Order reservation expired',
            'message' => 'Order '.$this->orderNumber.' was cancelled because payment was not completed before its inventory reservation expired.',
            'severity' => 'warning',
            'action' => [
                'type' => 'open_order',
                'label' => 'View order',
                'url' => $this->orderUrl(),
            ],
            'metadata' => [
                'order_id' => $this->orderId,
                'order_number' => $this->orderNumber,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Order '.$this->orderNumber.' expired')
            ->greeting('Hello, '.($notifiable->username ?? 'there').'!')
            ->line('Your order was cancelled because payment was not completed before its inventory reservation expired.')
            ->line('No successful payment was recorded for this cancellation.')
            ->action('View order', $this->orderUrl());
    }

    private function orderUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/orders/'.$this->orderId;
    }
}
