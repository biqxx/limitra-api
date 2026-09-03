<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SupportTicketCustomerNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly int $ticketId,
        private readonly string $ticketNumber,
        private readonly string $subject,
        private readonly string $status,
        private readonly string $kind,
        private readonly ?string $message = null,
    ) {}

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels(
            $notifiable,
            'support.ticket.customer_update',
            ['database', 'mail'],
        );
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications', 'mail' => 'notifications'];
    }

    public function viaConnections(): array
    {
        return ['database' => 'sync', 'mail' => (string) config('queue.default')];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'support.ticket.customer_update',
            'title' => $this->kind === 'reply' ? 'Support replied to your ticket' : 'Support ticket status updated',
            'message' => $this->summary(),
            'severity' => 'info',
            'action' => [
                'type' => 'open_support_ticket',
                'label' => 'View ticket',
                'url' => $this->customerUrl(),
            ],
            'metadata' => [
                'ticket_id' => $this->ticketId,
                'ticket_number' => $this->ticketNumber,
                'status' => $this->status,
                'kind' => $this->kind,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Support ticket update: '.$this->ticketNumber)
            ->line($this->summary());

        if ($notifiable instanceof User) {
            $mail->action('View ticket', $this->customerUrl());
        }

        return $mail;
    }

    private function summary(): string
    {
        if ($this->kind === 'reply' && $this->message) {
            return Str::limit($this->message, 180);
        }

        return 'Your ticket “'.$this->subject.'” is now '.$this->status.'.';
    }

    private function customerUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/account/support/'.$this->ticketId;
    }
}
