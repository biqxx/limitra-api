<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $modelConstraints = [
            'min_length' => 3,
            'max_length' => 255,
            'pattern' => '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._:-]*$/',
        ];
        $settings = [
            [
                'key' => 'ai.primary_model',
                'label' => 'Primary AI model',
                'description' => 'OpenRouter model used for standard customer-support requests.',
                'value' => 'inclusionai/ling-3.0-flash',
            ],
            [
                'key' => 'ai.fallback_model',
                'label' => 'Fallback AI model',
                'description' => 'OpenRouter model used when the primary model cannot complete a request.',
                'value' => 'upstage/solar-pro4',
            ],
            [
                'key' => 'ai.high_reasoning_model',
                'label' => 'High-reasoning AI model',
                'description' => 'OpenRouter model prioritized for conversations explicitly marked as high reasoning.',
                'value' => 'deepseek/deepseek-v4-flash-0731',
            ],
        ];

        DB::table('business_settings')->insert(array_map(
            fn (array $setting): array => [
                ...$setting,
                'group' => 'ai',
                'type' => 'string',
                'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                'constraints' => json_encode($modelConstraints, JSON_THROW_ON_ERROR),
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $settings,
        ));

        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->whereIn('key', [
            'ai.primary_model',
            'ai.fallback_model',
            'ai.high_reasoning_model',
        ])->delete();

        Cache::forget('business_settings.values.v1');
    }
};
