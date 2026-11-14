<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'microstore_id')) {
                $table->foreignId('microstore_id')
                    ->nullable()
                    ->after('checkout_quote_id')
                    ->constrained('microstores')
                    ->nullOnDelete();
                $table->index(['microstore_id', 'payment_status']);
            }
        });

        $now = now();
        $settings = [
            [
                'key' => 'microstores.require_moderation',
                'group' => 'microstores',
                'label' => 'Require microstore moderation',
                'description' => 'Whether newly submitted creator/celebrity microstores require admin approval before becoming publicly active.',
                'type' => 'boolean',
                'value' => json_encode(false, JSON_THROW_ON_ERROR),
                'constraints' => null,
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'microstores.attribution_window_days',
                'group' => 'microstores',
                'label' => 'Microstore attribution window (days)',
                'description' => 'Number of days after a customer clicks a product in a celebrity/creator microstore during which a purchase is attributed to that store.',
                'type' => 'integer',
                'value' => json_encode(30, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 1, 'max' => 365], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('business_settings')->updateOrInsert(
                ['key' => $setting['key']],
                $setting,
            );
        }

        Cache::forget('business_settings.values.v1');
        Cache::forget('business_settings.public.v1');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'microstore_id')) {
                $table->dropIndex(['microstore_id', 'payment_status']);
                $table->dropConstrainedForeignId('microstore_id');
            }
        });

        DB::table('business_settings')->whereIn('key', [
            'microstores.require_moderation',
            'microstores.attribution_window_days',
        ])->delete();

        Cache::forget('business_settings.values.v1');
        Cache::forget('business_settings.public.v1');
    }
};
