<?php

namespace App\Notifications\Affiliate;

use App\Models\Affiliate\AffiliateApplication;
use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateApplicationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        private readonly int $applicationId,
        private readonly int $expectedReviewDays = 3,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return $this->preferredChannels($notifiable, 'affiliate.application_submitted', ['database', 'mail']);
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
            'event' => 'affiliate.application_submitted',
            'title' => 'Affiliate application received',
            'message' => "We received your affiliate application (#{$this->applicationId}). Our team will review it within {$this->expectedReviewDays} business days.",
            'severity' => 'info',
            'action' => [
                'type' => 'open_affiliate_application',
                'label' => 'View application status',
                'url' => rtrim((string) config('app.frontend_url'), '/').'/affiliate',
            ],
            'metadata' => [
                'application_id' => $this->applicationId,
                'expected_review_days' => $this->expectedReviewDays,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We received your Limitra Affiliate application')
            ->line("Thank you for applying to join the Limitra Affiliate Program (Application #{$this->applicationId}).")
            ->line("Our team is reviewing your application and will notify you by email within {$this->expectedReviewDays} business days.");
    }

    public static function forApplication(AffiliateApplication $application, int $expectedReviewDays = 3): self
    {
        return new self((int) $application->id, $expectedReviewDays);
    }
}

