<?php

namespace App\Notifications\Microstore;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MicrostoreRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $storeName,
        private readonly string $storeSlug,
        private readonly bool $pendingApproval,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'microstore.registered', ['database', 'mail']);
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['database' => 'notifications', 'mail' => 'notifications'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'mail' => (string) config('queue.default')];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'microstore.registered',
            'title' => $this->pendingApproval ? 'Store registration received' : 'Your store is live',
            'message' => $this->pendingApproval
                ? "Your store \"{$this->storeName}\" has been registered and is pending admin approval."
                : "Your store \"{$this->storeName}\" has been created and is now active.",
            'severity' => 'info',
            'action' => [
                'type' => 'open_microstore',
                'label' => 'Manage store',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/my-microstore',
            ],
            'metadata' => [
                'store_name' => $this->storeName,
                'store_slug' => $this->storeSlug,
                'pending_approval' => $this->pendingApproval,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->pendingApproval
                ? "We received your store registration: {$this->storeName}"
                : "Your Limitra store {$this->storeName} is ready");

        if ($this->pendingApproval) {
            $mail->line("Your store \"{$this->storeName}\" (/{$this->storeSlug}) has been registered and is currently pending admin review.")
                ->line('We will send you another email as soon as an administrator approves your store.');
        } else {
            $mail->line("Your store \"{$this->storeName}\" (/{$this->storeSlug}) has been created and is now active.")
                ->action('Manage Your Store', rtrim((string) config('app.frontend_url'), '/').'/my-microstore');
        }

        return $mail;
    }
}

