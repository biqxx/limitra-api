<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('pending_email')->nullable()->unique();
            $table->string('email_change_otp')->nullable();
            $table->timestamp('email_change_expires_at')->nullable();
            $table->unsignedTinyInteger('email_change_attempts')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_otp')->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verification_sent_at')->nullable();
            $table->unsignedTinyInteger('email_verification_attempts')->default(0);
            $table->string('password');
            $table->enum('role', ['user', 'affiliate', 'admin', 'staff'])->default('user')->index();
            $table->rememberToken();
            $table->timestamps();
        });

        // OTP / password-reset tokens.
        // expires_at lets us enforce a TTL without relying on created_at math.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');          // bcrypt-hashed OTP or reset token
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name', 120);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
