<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('reservation_expired_notification_queued_at')->nullable();
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->timestamp('initiated_notification_queued_at')->nullable();
            $table->timestamp('processed_notification_queued_at')->nullable();
            $table->timestamp('attention_notification_queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn([
                'initiated_notification_queued_at',
                'processed_notification_queued_at',
                'attention_notification_queued_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reservation_expired_notification_queued_at');
        });
    }
};
