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
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'notifications'];
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
