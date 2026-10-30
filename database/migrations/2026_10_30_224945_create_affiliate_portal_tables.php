<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('affiliate_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->json('promotion_channels')->nullable();
            $table->string('website_url')->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('affiliate_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign', 100)->nullable();
            $table->string('landing_page', 255)->nullable();
            $table->string('slug', 100)->unique();
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('conversions_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['affiliate_id', 'is_active']);
        });

        Schema::create('affiliate_payout_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('bank_code', 20)->nullable();
            $table->string('account_number', 50);
            $table->string('account_name', 150);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['affiliate_id', 'is_default']);
        });

        Schema::create('affiliate_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_method_id')->nullable()->constrained('affiliate_payout_methods')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 30)->default('pending')->index();
            $table->string('reference', 100)->unique();
            $table->text('admin_notes')->nullable();
            $table->string('provider_reference')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });

        Schema::create('affiliate_promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->decimal('discount_percent', 5, 2)->default(5.00);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamps();

            $table->index(['affiliate_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_promo_codes');
        Schema::dropIfExists('affiliate_withdrawals');
        Schema::dropIfExists('affiliate_payout_methods');
        Schema::dropIfExists('affiliate_links');
        Schema::dropIfExists('affiliate_applications');
    }
};
