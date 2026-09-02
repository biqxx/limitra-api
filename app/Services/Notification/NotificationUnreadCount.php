<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class NotificationUnreadCount
{
    private const int TTL_SECONDS = 300;

    public function get(User $user): int
    {
        return (int) Cache::remember(
            $this->key($user->getKey()),
            self::TTL_SECONDS,
            fn (): int => $user->unreadNotifications()->count(),
        );
    }

    public function forget(User|int $user): void
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return 'notifications:unread:user:'.$userId;
    }
}
