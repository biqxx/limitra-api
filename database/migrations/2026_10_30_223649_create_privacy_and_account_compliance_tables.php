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
        Schema::create('privacy_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_token', 100)->nullable()->index();
            $table->string('policy_version', 20)->default('1.0');
            $table->boolean('essential')->default(true);
            $table->boolean('analytics')->default(false);
            $table->boolean('marketing')->default(false);
            $table->boolean('third_party_sharing')->default(false);
            $table->boolean('personalized_ads')->default(false);
            $table->string('source', 50)->default('web');
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('consented_at');
            $table->timestamps();

            $table->index(['user_id', 'consented_at']);
        });

        Schema::create('account_data_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('completed');
            $table->json('data')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
            $table->boolean('is_age_verified')->default(false);
            $table->timestamp('age_verified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_scheduled_for', 'is_age_verified', 'age_verified_at']);
        });

        Schema::dropIfExists('account_data_exports');
        Schema::dropIfExists('privacy_consents');
    }
};
