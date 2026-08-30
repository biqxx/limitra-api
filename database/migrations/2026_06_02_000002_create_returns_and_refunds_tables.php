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
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'received', 'completed'])->default('pending');
            $table->enum('resolution', ['refund', 'store_credit', 'replacement']);
            $table->char('currency', 3);
            $table->decimal('requested_total', 14, 2);
            $table->decimal('approved_total', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('approved_quantity')->nullable();
            $table->string('reason', 50);
            $table->text('notes')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('requested_amount', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id']);
            $table->index(['order_item_id', 'return_request_id']);
        });

        Schema::create('return_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['return_request_id', 'sort_order']);
        });

        Schema::create('return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->enum('source', ['customer', 'staff', 'payment', 'system']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['return_request_id', 'created_at']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 100)->unique();
            $table->string('provider', 30)->default('paystack');
            $table->enum('method', ['original_payment']);
            $table->enum('status', ['initiating', 'pending', 'processing', 'needs_attention', 'processed', 'failed'])->default('initiating');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->unsignedBigInteger('amount_minor');
            $table->string('provider_refund_id')->nullable()->index();
            $table->string('provider_reference')->nullable()->index();
            $table->text('reason');
            $table->text('failure_message')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['return_request_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        DB::table('business_settings')->insert([
            'key' => 'returns.max_images',
            'group' => 'returns',
            'label' => 'Maximum return images',
            'description' => 'Maximum number of evidence images accepted with a return request.',
            'type' => 'integer',
            'value' => json_encode(5, JSON_THROW_ON_ERROR),
            'constraints' => json_encode(['min' => 0, 'max' => 10], JSON_THROW_ON_ERROR),
            'is_public' => false,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->where('key', 'returns.max_images')->delete();
        Cache::forget('business_settings.values.v1');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('return_events');
        Schema::dropIfExists('return_images');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('return_requests');
    }
};
