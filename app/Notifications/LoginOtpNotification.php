<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
        private readonly int $ttlMinutes = 10,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Limitra Login Code')
            ->greeting('Hello!')
            ->line('Use the verification code below to complete your login:')
            ->line('## '.$this->otp)
            ->line("This code will expire in **{$this->ttlMinutes} minutes**.")
            ->line('If you did not request this login code, you can safely ignore this email.');
    }
}
