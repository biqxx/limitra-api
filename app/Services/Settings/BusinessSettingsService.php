<?php

namespace App\Services\Settings;

use App\Models\Settings\BusinessSetting;
use App\Models\Settings\BusinessSettingChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class BusinessSettingsService
{
    private const CACHE_KEY = 'business_settings.values.v1';

    public function value(string $key): mixed
    {
        $values = Cache::rememberForever(self::CACHE_KEY, fn (): array => BusinessSetting::query()
            ->pluck('value', 'key')
            ->all());

        if (! array_key_exists($key, $values)) {
            throw new LogicException("Business setting [{$key}] is not defined.");
        }

        return $values[$key];
    }

    /**
     * @param  array<int, array{key: string, value: mixed}>  $changes
     * @return Collection<int, BusinessSetting>
     */
    public function update(array $changes, User $actor): Collection
    {
        $updated = DB::transaction(function () use ($changes, $actor): Collection {
            /** @var Collection<string, BusinessSetting> $settings */
            $settings = BusinessSetting::query()->lockForUpdate()->get()->keyBy('key');
            $prospectiveValues = $settings->mapWithKeys(
                fn (BusinessSetting $setting): array => [$setting->key => $setting->value],
            )->all();

            foreach ($changes as $index => $change) {
                $setting = $settings->get($change['key']);

                if (! $setting) {
                    throw ValidationException::withMessages([
                        "settings.{$index}.key" => ['The selected business setting is invalid.'],
                    ]);
                }

                $this->validateValue($setting, $change['value'], $index);
                $prospectiveValues[$setting->key] = $change['value'];
            }

            $this->validateRelatedValues($prospectiveValues);

            return collect($changes)->map(function (array $change) use ($settings, $actor): BusinessSetting {
                /** @var BusinessSetting $setting */
                $setting = $settings->get($change['key']);
                $oldValue = $setting->value;

                if ($oldValue === $change['value']) {
                    return $setting;
                }

                $version = $setting->version + 1;
                $setting->forceFill([
                    'value' => $change['value'],
                    'version' => $version,
                    'updated_by' => $actor->id,
                ])->save();

                BusinessSettingChange::query()->create([
                    'business_setting_id' => $setting->id,
                    'changed_by' => $actor->id,
                    'old_value' => $oldValue,
                    'new_value' => $change['value'],
                    'version' => $version,
                    'created_at' => now(),
                ]);

                return $setting;
            });
        });

        Cache::forget(self::CACHE_KEY);

        return $updated->each->load('updatedBy');
    }

    private function validateValue(BusinessSetting $setting, mixed $value, int $index): void
    {
        $constraints = $setting->constraints ?? [];
        $valid = match ($setting->type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value)
                && (! isset($constraints['min']) || $value >= $constraints['min'])
                && (! isset($constraints['max']) || $value <= $constraints['max']),
            'string' => is_string($value)
                && (! isset($constraints['options']) || in_array($value, $constraints['options'], true)),
            'array' => $this->validArray($value, $constraints),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                "settings.{$index}.value" => ["The value is invalid for {$setting->key}."],
            ]);
        }
    }

    private function validArray(mixed $value, array $constraints): bool
    {
        if (! is_array($value) || array_is_list($value) === false) {
            return false;
        }

        if (isset($constraints['min_items']) && count($value) < $constraints['min_items']) {
            return false;
        }

        if (isset($constraints['max_items']) && count($value) > $constraints['max_items']) {
            return false;
        }

        if (($constraints['distinct'] ?? false) && count(array_unique($value, SORT_REGULAR)) !== count($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (($constraints['item_type'] ?? null) === 'string' && ! is_string($item)) {
                return false;
            }

            if (isset($constraints['item_options']) && ! in_array($item, $constraints['item_options'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    private function validateRelatedValues(array $values): void
    {
        if ($values['wallet.minimum_deposit_minor'] > $values['wallet.maximum_deposit_minor']) {
            throw ValidationException::withMessages([
                'settings' => ['The maximum wallet deposit must be greater than or equal to the minimum deposit.'],
            ]);
        }
    }
}
