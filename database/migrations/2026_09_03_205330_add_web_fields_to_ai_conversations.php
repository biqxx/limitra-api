<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['social_account_id']);
            $table->foreignId('social_account_id')->nullable()->change();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->index(['user_id', 'platform', 'status', 'last_message_at'], 'conversations_web_index');
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('client_message_id', 100)->nullable();
            $table->foreignId('request_message_id')->nullable()
                ->constrained('conversation_messages')->nullOnDelete();

            $table->unique(['conversation_id', 'client_message_id']);
            $table->index(['conversation_id', 'request_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropForeign(['request_message_id']);
            $table->dropUnique(['conversation_id', 'client_message_id']);
            $table->dropIndex(['conversation_id', 'request_message_id']);
            $table->dropColumn(['client_message_id', 'request_message_id']);
        });

        DB::table('conversations')->whereNull('social_account_id')->delete();

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_web_index');
            $table->dropForeign(['social_account_id']);
            $table->foreignId('social_account_id')->nullable(false)->change();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->dropColumn(['context', 'last_message_at', 'closed_at']);
        });
    }
};
