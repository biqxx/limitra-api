<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomaticRefundProcessedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
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
