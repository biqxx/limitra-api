<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationEventSetting;
use App\Models\Notification\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceService
{
    private const string SETTINGS_CACHE_KEY = 'notification:event-settings:v1';

    private const array API_TO_CHANNEL = [
        'in_app' => 'database',
        'email' => 'mail',
    ];

    public function channelsFor(User $user, string $event, array $requestedChannels): array
    {
        $setting = $this->settings()[$event] ?? null;

        if ($setting === null) {
            return $requestedChannels;
        }

        $overrides = $this->userOverrides($user)[$event] ?? [];

        return array_values(array_filter(
            $requestedChannels,
            function (string $channel) use ($setting, $overrides): bool {
                if (! in_array($channel, $setting['available_channels'], true)) {
                    return false;
                }

                if (in_array($channel, $setting['required_channels'], true)) {
                    return true;
                }

                return $overrides[$channel]
                    ?? in_array($channel, $setting['default_channels'], true);
            },
        ));
    }

    public function matrix(User $user): array
    {
        $overrides = $this->userOverrides($user);

        return collect($this->settings())
            ->map(function (array $setting) use ($overrides): array {
                $eventOverrides = $overrides[$setting['event']] ?? [];
                $channels = [];

                foreach ($setting['available_channels'] as $channel) {
                    $alias = $this->channelAlias($channel);
                    $required = in_array($channel, $setting['required_channels'], true);
                    $channels[$alias] = [
                        'enabled' => $required || ($eventOverrides[$channel]
                            ?? in_array($channel, $setting['default_channels'], true)),
                        'required' => $required,
                    ];
                }

                return [
                    'event' => $setting['event'],
                    'label' => $setting['label'],
                    'category' => $setting['category'],
                    'channels' => $channels,
                ];
            })
            ->values()
            ->all();
    }

    public function replace(User $user, array $preferences): array
    {
        $settings = $this->settings();
        $rows = [];
        $now = now();

        foreach ($preferences as $index => $preference) {
            $event = $preference['event'];
            $setting = $settings[$event] ?? null;

            if ($setting === null) {
                throw ValidationException::withMessages([
                    "preferences.{$index}.event" => ['The selected notification event is invalid.'],
                ]);
            }

            foreach ($preference['channels'] as $alias => $enabled) {
                $channel = self::API_TO_CHANNEL[$alias] ?? null;
                if ($channel === null || ! in_array($channel, $setting['available_channels'], true)) {
                    throw ValidationException::withMessages([
                        "preferences.{$index}.channels.{$alias}" => ['The selected notification channel is invalid for this event.'],
                    ]);
                }
                if (! $enabled && in_array($channel, $setting['required_channels'], true)) {
                    throw ValidationException::withMessages([
                        "preferences.{$index}.channels.{$alias}" => ['This transactional notification channel cannot be disabled.'],
                    ]);
                }

                $rows[] = [
                    'user_id' => $user->getKey(),
                    'event' => $event,
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($user, $rows): void {
            NotificationPreference::query()->where('user_id', $user->getKey())->delete();
            if ($rows !== []) {
                NotificationPreference::query()->insert($rows);
            }
        });
        $this->forgetUser($user);

        return $this->matrix($user);
    }

    public function adminSettings(): array
    {
        return collect($this->settings())->map(fn (array $setting): array => [
            'event' => $setting['event'],
            'label' => $setting['label'],
            'category' => $setting['category'],
            'available_channels' => $this->aliases($setting['available_channels']),
            'default_channels' => $this->aliases($setting['default_channels']),
            'required_channels' => $this->aliases($setting['required_channels']),
        ])->values()->all();
    }

    public function updateDefaults(string $event, array $aliases): array
    {
        $setting = $this->settings()[$event] ?? null;
        if ($setting === null) {
            abort(404, 'Notification event setting not found.');
        }

        $channels = [];
        foreach ($aliases as $index => $alias) {
            $channel = self::API_TO_CHANNEL[$alias] ?? null;
            if ($channel === null || ! in_array($channel, $setting['available_channels'], true)) {
                throw ValidationException::withMessages([
                    "default_channels.{$index}" => ['The selected default channel is invalid for this event.'],
                ]);
            }
            $channels[] = $channel;
        }
        $channels = array_values(array_unique($channels));

        foreach ($setting['required_channels'] as $required) {
            if (! in_array($required, $channels, true)) {
                throw ValidationException::withMessages([
                    'default_channels' => ['Required transactional channels must remain enabled by default.'],
                ]);
            }
        }

        NotificationEventSetting::query()->findOrFail($event)->update([
            'default_channels' => $channels,
        ]);
        Cache::forget(self::SETTINGS_CACHE_KEY);

        return collect($this->adminSettings())->firstWhere('event', $event);
    }

    private function settings(): array
    {
        return Cache::rememberForever(self::SETTINGS_CACHE_KEY, fn (): array => NotificationEventSetting::query()
            ->orderBy('category')
            ->orderBy('event')
            ->get()
            ->mapWithKeys(fn (NotificationEventSetting $setting): array => [
                $setting->event => [
                    'event' => $setting->event,
                    'label' => $setting->label,
                    'category' => $setting->category,
                    'available_channels' => $setting->available_channels,
                    'default_channels' => $setting->default_channels,
                    'required_channels' => $setting->required_channels,
                ],
            ])
            ->all());
    }

    private function userOverrides(User $user): array
    {
        return Cache::rememberForever($this->userCacheKey($user), fn (): array => NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get(['event', 'channel', 'enabled'])
            ->groupBy('event')
            ->map(fn ($preferences): array => $preferences
                ->mapWithKeys(fn (NotificationPreference $preference): array => [
                    $preference->channel => $preference->enabled,
                ])
                ->all())
            ->all());
    }

    private function forgetUser(User $user): void
    {
        Cache::forget($this->userCacheKey($user));
    }

    private function userCacheKey(User $user): string
    {
        return 'notification:preferences:user:'.$user->getKey().':v1';
    }

    private function aliases(array $channels): array
    {
        return array_values(array_map($this->channelAlias(...), $channels));
    }

    private function channelAlias(string $channel): string
    {
        return array_search($channel, self::API_TO_CHANNEL, true) ?: $channel;
    }
}
