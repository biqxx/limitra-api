<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AutomaticRefundStaffAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly int $orderId,
        private readonly string $orderNumber,
        private readonly string $refundReference,
        private readonly string $customerEmail,
        private readonly string $failureMessage,
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
            ->error()
            ->subject('Action required: automatic refund '.$this->refundReference)
            ->line('An automatic late-payment refund requires manual attention.')
            ->line('Order: '.$this->orderNumber)
            ->line('Customer: '.$this->customerEmail)
            ->line('Provider response: '.$this->failureMessage)
            ->action('Review order', $this->adminOrderUrl());
    }

    private function adminOrderUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/admin/orders/'.$this->orderId;
    }
}
