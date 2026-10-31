<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wallet_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_deposits', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->index();
            }
            if (! Schema::hasColumn('wallet_deposits', 'request_hash')) {
                $table->string('request_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('wallet_deposits', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->index();
            }
            if (! Schema::hasColumn('wallet_deposits', 'failure_reason')) {
                $table->string('failure_reason', 255)->nullable();
            }
        });

        // Add composite index if supported
        try {
            Schema::table('wallet_deposits', function (Blueprint $table): void {
                $table->index(['user_id', 'idempotency_key'], 'wallet_deposits_user_idempotency_idx');
            });
        } catch (Throwable) {
            // Already exists or unsupported
        }

        // Seed wallet business settings
        $now = now();
        $settings = [
            [
                'key' => 'wallet.allowed_callback_domains',
                'group' => 'wallet',
                'label' => 'Allowed Deposit Callback Domains',
                'description' => 'Allowlisted frontend domain hostnames for wallet deposit callback URLs.',
                'type' => 'array',
                'value' => json_encode(['limitra.com', 'app.limitra.com', 'staging.limitra.com', 'limitra.test', 'localhost', '127.0.0.1'], JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min_items' => 1, 'distinct' => true, 'item_type' => 'string'], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'wallet.deposit_expiration_minutes',
                'group' => 'wallet',
                'label' => 'Wallet Deposit Expiration Minutes',
                'description' => 'Duration in minutes after which an unpaid wallet deposit expires.',
                'type' => 'integer',
                'value' => json_encode(1440, JSON_THROW_ON_ERROR), // 24 hours
                'constraints' => json_encode(['min' => 15, 'max' => 10080], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('business_settings')->insertOrIgnore($setting);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_deposits', function (Blueprint $table): void {
            if (Schema::hasColumn('wallet_deposits', 'idempotency_key')) {
                $table->dropColumn(['idempotency_key', 'request_hash', 'expires_at', 'failure_reason']);
            }
        });

        DB::table('business_settings')->whereIn('key', [
            'wallet.allowed_callback_domains',
            'wallet.deposit_expiration_minutes',
        ])->delete();
    }
};
