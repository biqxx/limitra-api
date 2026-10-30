<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('theme', 30)->default('light');
            $table->string('locale', 10)->default('en');
            $table->char('currency', 3)->default('NGN');
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('email_notifications')->default(true);
            $table->boolean('sms_notifications')->default(false);
            $table->boolean('push_notifications')->default(false);
            $table->boolean('in_app_notifications')->default(true);
            $table->boolean('marketing_emails')->default(false);
            $table->boolean('marketing_sms')->default(false);
            $table->boolean('marketing_push')->default(false);
            $table->boolean('marketing_in_app')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
