<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Modify affiliate_payout_methods
        Schema::table('affiliate_payout_methods', function (Blueprint $table): void {
            $table->text('account_number')->change();
            $table->boolean('is_verified')->default(false)->after('is_default');
            $table->timestamp('verified_at')->nullable()->after('is_verified');
        });

        // 2. Create immutable affiliate_ledger_entries
        Schema::create('affiliate_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index(); // earning, hold, release, settlement, reversal, adjustment
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('NGN');
            $table->decimal('balance_after', 14, 2);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('description', 255);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['affiliate_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // 3. Alter affiliate_withdrawals
        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->index()->after('reference');
            $table->string('request_hash', 64)->nullable()->after('idempotency_key');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('request_hash');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('settled_at')->nullable()->after('processed_at');
            $table->text('failure_reason')->nullable()->after('admin_notes');
            $table->index('provider_reference');
        });

        // 4. Alter affiliates
        Schema::table('affiliates', function (Blueprint $table): void {
            $table->string('current_tier', 50)->default('Bronze')->after('commission_rate');
        });

        // 5. Create affiliate_clicks
        Schema::create('affiliate_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_link_id')->nullable()->constrained('affiliate_links')->nullOnDelete();
            $table->string('destination_url', 500);
            $table->char('ip_hash', 64)->index();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_self_referral')->default(false);
            $table->boolean('converted')->default(false)->index();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['affiliate_id', 'created_at']);
            $table->index(['affiliate_link_id', 'created_at']);
        });

        // 6. Create affiliate_tier_histories
        Schema::create('affiliate_tier_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('from_tier', 50);
            $table->string('to_tier', 50);
            $table->unsignedInteger('qualifying_delivered_orders_count')->default(0);
            $table->decimal('qualifying_sales_volume', 14, 2)->default(0.00);
            $table->string('reason', 255);
            $table->timestamp('created_at')->index();

            $table->index(['affiliate_id', 'created_at']);
        });

        // 7. Create affiliate_resources
        Schema::create('affiliate_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('category', 50)->default('general')->index();
            $table->string('file_path', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->boolean('is_published')->default(true)->index();
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamps();
        });

        // 8. Add affiliate_link_id and affiliate_promo_code_id to affiliate_sales
        Schema::table('affiliate_sales', function (Blueprint $table): void {
            $table->foreignId('affiliate_link_id')->nullable()->constrained('affiliate_links')->nullOnDelete();
            $table->foreignId('affiliate_promo_code_id')->nullable()->constrained('affiliate_promo_codes')->nullOnDelete();
        });

        // 9. Seed Business Settings for Affiliates
        $now = now();
        $settings = [
            [
                'key' => 'affiliates.min_withdrawal_amount',
                'group' => 'affiliates',
                'label' => 'Minimum affiliate withdrawal amount',
                'description' => 'Minimum withdrawal payout amount in NGN.',
                'type' => 'integer',
                'value' => json_encode(5000, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 1000, 'max' => 5000000], JSON_THROW_ON_ERROR),
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'affiliates.currency',
                'group' => 'affiliates',
                'label' => 'Affiliate currency',
                'description' => 'Authoritative currency for affiliate earnings and payouts.',
                'type' => 'string',
                'value' => json_encode('NGN', JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['options' => ['NGN']], JSON_THROW_ON_ERROR),
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'affiliates.attribution_window_days',
                'group' => 'affiliates',
                'label' => 'Attribution window days',
                'description' => 'Number of days an affiliate tracking click remains valid for conversion attribution.',
                'type' => 'integer',
                'value' => json_encode(30, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 1, 'max' => 180], JSON_THROW_ON_ERROR),
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'affiliates.qualification_window_days',
                'group' => 'affiliates',
                'label' => 'Tier qualification rolling window days',
                'description' => 'Rolling window in days to calculate delivered order volume for affiliate tier upgrades.',
                'type' => 'integer',
                'value' => json_encode(30, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 7, 'max' => 365], JSON_THROW_ON_ERROR),
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'affiliates.allowed_redirect_domains',
                'group' => 'affiliates',
                'label' => 'Allowed affiliate redirect domains',
                'description' => 'Allowlisted domains for affiliate custom link redirects to prevent open redirects.',
                'type' => 'array',
                'value' => json_encode(['localhost', '127.0.0.1', 'limitra.test', 'limitra.com'], JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min_items' => 1], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'affiliates.tiers',
                'group' => 'affiliates',
                'label' => 'Affiliate tiers configuration',
                'description' => 'Tier thresholds and corresponding commission rates.',
                'type' => 'array',
                'value' => json_encode([
                    ['name' => 'Bronze', 'min_orders' => 0, 'commission_rate' => 5.00],
                    ['name' => 'Silver', 'min_orders' => 10, 'commission_rate' => 7.50],
                    ['name' => 'Gold', 'min_orders' => 25, 'commission_rate' => 10.00],
                    ['name' => 'Platinum', 'min_orders' => 50, 'commission_rate' => 12.50],
                ], JSON_THROW_ON_ERROR),
                'constraints' => null,
                'is_public' => true,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('business_settings')->upsert($settings, ['key'], [
            'group', 'label', 'description', 'type', 'value', 'constraints', 'is_public', 'version', 'updated_at',
        ]);
        Cache::forget('business_settings.values.v1');
        Cache::forget('business_settings.public.v1');

        // 10. Seed permissions
        $permissions = [
            ['name' => 'payouts.pay', 'domain' => 'payouts', 'description' => 'Process and settle affiliate payout withdrawals.', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'affiliates.manage', 'domain' => 'affiliates', 'description' => 'Manage affiliate program rules, tiers, and resources.', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('permissions')->upsert($permissions, ['name'], ['domain', 'description', 'is_system', 'updated_at']);

        $adminRole = DB::table('roles')->where('name', 'admin')->first();
        if ($adminRole) {
            $permIds = DB::table('permissions')->whereIn('name', ['payouts.pay', 'affiliates.manage'])->pluck('id');
            foreach ($permIds as $permId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $adminRole->id,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('business_settings')->whereIn('key', [
            'affiliates.min_withdrawal_amount',
            'affiliates.currency',
            'affiliates.attribution_window_days',
            'affiliates.qualification_window_days',
            'affiliates.allowed_redirect_domains',
            'affiliates.tiers',
        ])->delete();
        Cache::forget('business_settings.values.v1');
        Cache::forget('business_settings.public.v1');

        Schema::table('affiliate_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('affiliate_promo_code_id');
            $table->dropConstrainedForeignId('affiliate_link_id');
        });

        Schema::dropIfExists('affiliate_resources');
        Schema::dropIfExists('affiliate_tier_histories');
        Schema::dropIfExists('affiliate_clicks');

        Schema::table('affiliates', function (Blueprint $table): void {
            $table->dropColumn('current_tier');
        });

        Schema::table('affiliate_withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['provider_reference']);
            $table->dropColumn([
                'idempotency_key',
                'request_hash',
                'approved_by',
                'approved_at',
                'settled_at',
                'failure_reason',
            ]);
        });

        Schema::dropIfExists('affiliate_ledger_entries');

        Schema::table('affiliate_payout_methods', function (Blueprint $table): void {
            $table->dropColumn(['is_verified', 'verified_at']);
        });
    }
};
