<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\Notification\NotificationPreferenceService;

trait RespectsNotificationPreferences
{
    protected function preferredChannels(object $notifiable, string $event, array $channels): array
    {
        if (! $notifiable instanceof User) {
            return $channels;
        }

        return app(NotificationPreferenceService::class)->channelsFor($notifiable, $event, $channels);
    }
}
