<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 160);
            $table->string('status', 20)->default('draft');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('eligibility');
            $table->unsignedInteger('coupon_expiry_days')->default(7);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('reward_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('type', 30);
            $table->unsignedBigInteger('value_minor')->default(0);
            $table->unsignedInteger('weight_basis_points');
            $table->unsignedInteger('inventory_limit')->nullable();
            $table->unsignedInteger('inventory_awarded')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['reward_campaign_id', 'active', 'sort_order']);
        });

        Schema::create('reward_spins', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('reward_campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('reward_prize_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('status', 30);
            $table->string('idempotency_key', 100);
            $table->unsignedInteger('selection_roll');
            $table->unsignedInteger('total_weight_basis_points');
            $table->unsignedInteger('configuration_version');
            $table->json('configuration_snapshot');
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['reward_campaign_id', 'user_id', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_spins');
        Schema::dropIfExists('reward_prizes');
        Schema::dropIfExists('reward_campaigns');
    }
};
