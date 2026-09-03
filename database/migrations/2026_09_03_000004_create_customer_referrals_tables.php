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
            $table->foreignId('reversal_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->unsignedBigInteger('reward_amount_minor')->nullable();
            $table->char('reward_currency', 3)->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('customer_referral_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_referral_code_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->char('target_hash', 64);
            $table->text('target_ciphertext');
            $table->string('target_masked', 255);
            $table->string('status', 30)->default('queued');
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'created_at']);
            $table->index(['referrer_id', 'target_hash', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('customer_referral_share_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_referral_code_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->char('shared_url_hash', 64);
            $table->string('shared_path', 500)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['customer_referral_code_id', 'channel', 'occurred_at']);
        });

        $now = now();
        DB::table('business_settings')->insert([
            $this->setting('referrals.attribution_days', 'Referral attribution window', 'Number of days a resolved customer or affiliate referral remains valid for signup.', 30, 1, 365, $now, true),
            $this->setting('referrals.invitation_daily_limit', 'Daily referral invitation limit', 'Maximum number of referral invitations a customer may create per day.', 20, 1, 100, $now),
            $this->setting('referrals.invitation_resend_days', 'Referral invitation resend window', 'Days before the same customer may invite the same contact again.', 7, 1, 90, $now),
        ]);
        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->whereIn('key', [
            'referrals.attribution_days',
            'referrals.invitation_daily_limit',
            'referrals.invitation_resend_days',
        ])->delete();
        Cache::forget('business_settings.values.v1');
        Schema::dropIfExists('customer_referral_share_events');
        Schema::dropIfExists('customer_referral_invitations');
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_referral_attributions');
        Schema::dropIfExists('customer_referral_codes');
    }

    /** @return array<string, mixed> */
    private function setting(
        string $key,
        string $label,
        string $description,
        int $value,
        int $minimum,
        int $maximum,
        mixed $now,
        bool $isPublic = false,
    ): array {
        return [
            'key' => $key,
            'group' => 'referrals',
            'label' => $label,
            'description' => $description,
            'type' => 'integer',
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'constraints' => json_encode(['min' => $minimum, 'max' => $maximum], JSON_THROW_ON_ERROR),
            'is_public' => $isPublic,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
