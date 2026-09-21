<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $name,
        private readonly string $token,
        private readonly ?string $roleName,
        private readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/staff/invitation#token='.rawurlencode($this->token);

        $message = (new MailMessage)
            ->subject('You have been invited to join Limitra staff')
            ->greeting('Hello, '.$this->name.'!')
            ->line('You have been invited to join the Limitra staff team.');

        if ($this->roleName) {
            $message->line('Operational role: '.str_replace('_', ' ', $this->roleName).'.');
        }

        return $message
            ->action('Accept invitation', $url)
            ->line('This invitation expires '.$this->expiresAt->diffForHumans().'.')
            ->line('If you were not expecting this invitation, ignore this email.');
    }
}
