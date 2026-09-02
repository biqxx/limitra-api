<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $ipAddress,
        private readonly string $userAgent,
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
            'event' => 'account.login',
            'title' => 'New login detected',
            'message' => 'A new login to your account was detected from '.$this->userAgent.'.',
            'severity' => 'warning',
            'action' => [
                'type' => 'open_account_sessions',
                'label' => 'Review sessions',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/account/sessions',
            ],
            'metadata' => [
                'ip_address' => $this->ipAddress,
                'device' => $this->userAgent,
                'occurred_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Login to Your Account')
            ->greeting('Hello, '.($notifiable->profile?->first_name ?? $notifiable->username).'!')
            ->line('A new login was detected on your account.')
            ->line('**IP Address:** '.$this->ipAddress)
            ->line('**Device:** '.$this->userAgent)
            ->line('**Time:** '.now()->toDateTimeString().' UTC')
            ->line('If this was not you, please change your password immediately.');
    }
}
