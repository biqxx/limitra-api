<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtpNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Password Reset OTP')
            ->greeting('Hello!')
            ->line('You requested a password reset. Use the OTP below:')
            ->line('## '.$this->otp)
            ->line('This OTP is valid for **15 minutes**.')
            ->line('If you did not request a password reset, ignore this email.');
    }
}
