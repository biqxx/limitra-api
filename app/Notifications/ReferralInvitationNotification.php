<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $referrerName,
        private readonly string $code,
        private readonly ?string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->referrerName} invited you to Limitra")
            ->line("{$this->referrerName} invited you to shop on Limitra.");

        if ($this->message) {
            $mail->line($this->message);
        }

        return $mail->action(
            'Accept invitation',
            rtrim((string) config('app.frontend_url'), '/').'/ref/'.$this->code,
        );
    }
}
