<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Notification\NotificationUnreadCount;
use Illuminate\Notifications\Events\NotificationSent;

class InvalidateNotificationUnreadCount
{
    public function __construct(private readonly NotificationUnreadCount $counts) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel === 'database' && $event->notifiable instanceof User) {
            $this->counts->forget($event->notifiable);
        }
    }
}
