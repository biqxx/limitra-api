<?php

namespace App\Notifications\Microstore;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MicrostoreModeratedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly string $storeName,
        private readonly string $storeSlug,
        private readonly string $moderationStatus,
        private readonly ?string $notes = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'microstore.moderated', ['database', 'mail']);
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
        $approved = $this->moderationStatus === 'approved';

        return [
            'event' => 'microstore.moderated',
            'title' => $approved ? 'Your store has been approved' : "Store {$this->moderationStatus}",
            'message' => $approved
                ? "Your store \"{$this->storeName}\" has been approved by an administrator and is now live."
                : "Your store \"{$this->storeName}\" status was updated to {$this->moderationStatus}."
                    .($this->notes ? " Notes: {$this->notes}" : ''),
            'severity' => $approved ? 'success' : 'warning',
            'action' => [
                'type' => 'open_microstore',
                'label' => 'View store',
                'url' => rtrim((string) config('app.frontend_url'), '/')."/stores/{$this->storeSlug}",
            ],
            'metadata' => [
                'store_name' => $this->storeName,
                'store_slug' => $this->storeSlug,
                'moderation_status' => $this->moderationStatus,
                'notes' => $this->notes,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->moderationStatus === 'approved';

        $mail = (new MailMessage)
            ->subject($approved
                ? "Your store \"{$this->storeName}\" is approved!"
                : "Update on your store \"{$this->storeName}\"");

        if ($approved) {
            $mail->line("Great news! Your store \"{$this->storeName}\" has been approved by an administrator and is now live.")
                ->action('View Your Store', rtrim((string) config('app.frontend_url'), '/')."/stores/{$this->storeSlug}");
        } else {
            $mail->line("Your store \"{$this->storeName}\" moderation status has been updated to: {$this->moderationStatus}.")
                ->when($this->notes, fn (MailMessage $msg) => $msg->line("Notes: {$this->notes}"));
        }

        return $mail;
    }
}

