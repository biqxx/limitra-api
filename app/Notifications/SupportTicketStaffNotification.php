<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketStaffNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly int $ticketId,
        private readonly string $ticketNumber,
        private readonly string $subject,
        private readonly string $contactName,
        private readonly string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->preferredChannels(
            $notifiable,
            'support.ticket.staff_action_required',
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
            'event' => 'support.ticket.staff_action_required',
            'title' => $this->kind === 'created' ? 'New support ticket' : 'Support ticket updated by customer',
            'message' => $this->contactName.' needs help with “'.$this->subject.'”.',
            'severity' => 'warning',
            'action' => [
                'type' => 'open_admin_support_ticket',
                'label' => 'Review ticket',
                'url' => $this->adminUrl(),
            ],
            'metadata' => [
                'ticket_id' => $this->ticketId,
                'ticket_number' => $this->ticketNumber,
                'kind' => $this->kind,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Support action required: '.$this->ticketNumber)
            ->line($this->contactName.' needs help with “'.$this->subject.'”.')
            ->action('Review ticket', $this->adminUrl());
    }

    private function adminUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/admin/support/tickets/'.$this->ticketId;
    }
}
