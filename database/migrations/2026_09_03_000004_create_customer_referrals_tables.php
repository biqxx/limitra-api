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
        Schema::create('customer_referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_referral_attributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('session_hash', 64)->unique();
            $table->string('type', 20);
            $table->string('code', 20);
            $table->foreignId('customer_referral_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->foreignId('converted_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['type', 'code']);
            $table->index(['expires_at', 'converted_at']);
        });

        Schema::create('customer_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_referral_attribution_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('qualifying_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('reward_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->unsignedBigInteger('reward_amount_minor')->nullable();
            $table->char('reward_currency', 3)->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        $now = now();
        DB::table('business_settings')->insert([
            'key' => 'referrals.attribution_days',
            'group' => 'referrals',
            'label' => 'Referral attribution window',
            'description' => 'Number of days a resolved customer or affiliate referral remains valid for signup.',
            'type' => 'integer',
            'value' => json_encode(30, JSON_THROW_ON_ERROR),
            'constraints' => json_encode(['min' => 1, 'max' => 365], JSON_THROW_ON_ERROR),
            'is_public' => true,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->where('key', 'referrals.attribution_days')->delete();
        Cache::forget('business_settings.values.v1');
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_referral_attributions');
        Schema::dropIfExists('customer_referral_codes');
    }
};
