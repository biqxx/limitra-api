<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microstore_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('microstore_id')->constrained('microstores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 30)->default('pending');
            $table->string('payout_method', 50)->default('bank_transfer');
            $table->json('payout_details')->nullable();
            $table->string('reference', 100)->unique();
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['microstore_id', 'status']);
        });

        Schema::create('microstore_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('microstore_id')->constrained('microstores')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('customer_unread_count')->default(0);
            $table->unsignedInteger('owner_unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['microstore_id', 'customer_id']);
            $table->index(['microstore_id', 'last_message_at']);
        });

        Schema::create('microstore_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('microstore_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender_role', 20);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microstore_messages');
        Schema::dropIfExists('microstore_conversations');
        Schema::dropIfExists('microstore_withdrawals');
    }
};
