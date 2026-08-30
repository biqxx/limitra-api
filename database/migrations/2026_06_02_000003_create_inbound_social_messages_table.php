<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_social_messages', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32);
            $table->string('provider_message_id');
            $table->string('platform_sender_id');
            $table->string('platform_recipient_id');
            $table->string('username')->nullable();
            $table->text('message');
            $table->text('reply')->nullable();
            $table->timestamp('occurred_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'provider_message_id']);
            $table->index(['status', 'processing_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_social_messages');
    }
};
