<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group', 50)->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->enum('type', ['boolean', 'integer', 'string', 'array']);
            $table->json('value');
            $table->json('constraints')->nullable();
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('business_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('old_value');
            $table->json('new_value');
            $table->unsignedInteger('version');
            $table->timestamp('created_at');
            $table->index(['business_setting_id', 'created_at']);
        });

        $now = now();
        $settings = [
            ['key' => 'returns.window_days', 'group' => 'returns', 'label' => 'Return window', 'description' => 'Number of days after delivery in which a customer may submit a return.', 'type' => 'integer', 'value' => 14, 'constraints' => ['min' => 0, 'max' => 365], 'is_public' => true],
            ['key' => 'returns.allowed_resolutions', 'group' => 'returns', 'label' => 'Allowed return resolutions', 'description' => 'Resolutions a customer may request. Every submitted return still requires staff or admin approval.', 'type' => 'array', 'value' => ['refund', 'store_credit', 'replacement'], 'constraints' => ['min_items' => 1, 'distinct' => true, 'item_type' => 'string', 'item_options' => ['refund', 'store_credit', 'replacement']], 'is_public' => true],
            ['key' => 'referrals.enabled', 'group' => 'referrals', 'label' => 'Customer referrals enabled', 'description' => 'Whether customer referral attribution and rewards are available.', 'type' => 'boolean', 'value' => true, 'constraints' => null, 'is_public' => true],
            ['key' => 'referrals.reward_amount_minor', 'group' => 'referrals', 'label' => 'Referral reward amount', 'description' => 'Reward amount in the smallest currency unit, awarded after the first genuine delivered order.', 'type' => 'integer', 'value' => 700000, 'constraints' => ['min' => 0, 'max' => 100000000], 'is_public' => true],
            ['key' => 'referrals.currency', 'group' => 'referrals', 'label' => 'Referral reward currency', 'description' => 'ISO currency used for referral rewards.', 'type' => 'string', 'value' => 'NGN', 'constraints' => ['options' => ['NGN']], 'is_public' => true],
            ['key' => 'wallet.deposits_enabled', 'group' => 'wallet', 'label' => 'Wallet deposits enabled', 'description' => 'Whether customers may initialize wallet deposits.', 'type' => 'boolean', 'value' => true, 'constraints' => null, 'is_public' => true],
            ['key' => 'wallet.currency', 'group' => 'wallet', 'label' => 'Wallet currency', 'description' => 'ISO currency used for customer wallet balances and transactions.', 'type' => 'string', 'value' => 'NGN', 'constraints' => ['options' => ['NGN']], 'is_public' => true],
            ['key' => 'wallet.minimum_deposit_minor', 'group' => 'wallet', 'label' => 'Minimum wallet deposit', 'description' => 'Minimum wallet deposit in the smallest currency unit.', 'type' => 'integer', 'value' => 10000, 'constraints' => ['min' => 0, 'max' => 100000000], 'is_public' => true],
            ['key' => 'wallet.maximum_deposit_minor', 'group' => 'wallet', 'label' => 'Maximum wallet deposit', 'description' => 'Maximum wallet deposit in the smallest currency unit.', 'type' => 'integer', 'value' => 100000000, 'constraints' => ['min' => 0, 'max' => 1000000000], 'is_public' => true],
            ['key' => 'wallet.withdrawals_enabled', 'group' => 'wallet', 'label' => 'Wallet withdrawals enabled', 'description' => 'Whether customers may request wallet withdrawals.', 'type' => 'boolean', 'value' => false, 'constraints' => null, 'is_public' => true],
            ['key' => 'rewards.enabled', 'group' => 'rewards', 'label' => 'Rewards enabled', 'description' => 'Whether customer rewards and Spin & Win campaigns are available.', 'type' => 'boolean', 'value' => true, 'constraints' => null, 'is_public' => true],
            ['key' => 'reviews.max_images', 'group' => 'reviews', 'label' => 'Maximum review images', 'description' => 'Maximum number of images accepted with a product review.', 'type' => 'integer', 'value' => 5, 'constraints' => ['min' => 0, 'max' => 10], 'is_public' => false],
            ['key' => 'support.max_attachments', 'group' => 'support', 'label' => 'Maximum support attachments', 'description' => 'Maximum number of attachments accepted on a support message.', 'type' => 'integer', 'value' => 5, 'constraints' => ['min' => 0, 'max' => 10], 'is_public' => false],
            ['key' => 'media.image_max_size_kb', 'group' => 'media', 'label' => 'Maximum image size', 'description' => 'Maximum uploaded image size in kilobytes.', 'type' => 'integer', 'value' => 5120, 'constraints' => ['min' => 100, 'max' => 20480], 'is_public' => false],
            ['key' => 'media.document_max_size_kb', 'group' => 'media', 'label' => 'Maximum document size', 'description' => 'Maximum uploaded document size in kilobytes.', 'type' => 'integer', 'value' => 10240, 'constraints' => ['min' => 100, 'max' => 51200], 'is_public' => false],
        ];

        DB::table('business_settings')->insert(array_map(
            fn (array $setting): array => [
                ...$setting,
                'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                'constraints' => $setting['constraints'] === null ? null : json_encode($setting['constraints'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $settings,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('business_setting_changes');
        Schema::dropIfExists('business_settings');
    }
};
