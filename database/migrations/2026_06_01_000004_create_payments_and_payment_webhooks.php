<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('saved_card_id')->nullable()->constrained('saved_cards')->nullOnDelete();
            $table->string('provider', 30)->default('paystack');
            $table->enum('method', ['card', 'bank_transfer']);
            $table->string('reference', 100)->unique();
            $table->enum('status', ['initializing', 'pending', 'succeeded', 'failed', 'abandoned'])->default('initializing');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->unsignedBigInteger('amount_minor');
            $table->string('customer_email');
            $table->text('callback_url')->nullable();
            $table->text('authorization_url')->nullable();
            $table->string('access_code')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->string('channel', 50)->nullable();
            $table->string('gateway_response')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->char('payload_hash', 64)->unique();
            $table->string('event', 100);
            $table->string('reference', 100)->nullable();
            $table->enum('status', ['received', 'processed', 'ignored', 'failed'])->default('received');
            $table->text('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['provider', 'event', 'status']);
            $table->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
    }
};
