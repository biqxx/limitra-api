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
        Schema::table('refunds', function (Blueprint $table) {
            $table->unsignedInteger('reconciliation_attempts')->default(0)->after('provider_metadata');
            $table->timestamp('last_reconciled_at')->nullable()->after('reconciliation_attempts');
            $table->timestamp('next_reconciliation_at')->nullable()->after('last_reconciled_at');
            $table->index(
                ['source', 'status', 'next_reconciliation_at'],
                'refund_reconciliation_due_idx',
            );
        });

        $now = now();
        $settings = [
            [
                'key' => 'payments.refund_reconciliation_delay_minutes',
                'group' => 'payments',
                'label' => 'Refund reconciliation delay',
                'description' => 'Minutes to wait before checking a newly pending automatic refund with the payment provider.',
                'type' => 'integer',
                'value' => 5,
                'constraints' => ['min' => 1, 'max' => 60],
            ],
            [
                'key' => 'payments.refund_reconciliation_interval_minutes',
                'group' => 'payments',
                'label' => 'Refund reconciliation interval',
                'description' => 'Minutes between checks for an automatic refund that is still pending or processing.',
                'type' => 'integer',
                'value' => 15,
                'constraints' => ['min' => 1, 'max' => 1440],
            ],
            [
                'key' => 'payments.refund_reconciliation_max_age_hours',
                'group' => 'payments',
                'label' => 'Maximum pending refund age',
                'description' => 'Hours before an unconfirmed automatic refund is escalated for staff attention.',
                'type' => 'integer',
                'value' => 48,
                'constraints' => ['min' => 1, 'max' => 720],
            ],
        ];

        DB::table('business_settings')->insert(array_map(
            fn (array $setting): array => [
                ...$setting,
                'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                'constraints' => json_encode($setting['constraints'], JSON_THROW_ON_ERROR),
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
            'payments.refund_reconciliation_delay_minutes',
            'payments.refund_reconciliation_interval_minutes',
            'payments.refund_reconciliation_max_age_hours',
        ])->delete();
        Cache::forget('business_settings.values.v1');

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refund_reconciliation_due_idx');
            $table->dropColumn([
                'reconciliation_attempts',
                'last_reconciled_at',
                'next_reconciliation_at',
            ]);
        });
    }
};
