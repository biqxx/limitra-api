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
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('email_hash', 64)->unique();
            $table->text('email_ciphertext');
            $table->string('email_masked', 255);
            $table->string('name', 120);
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_name_snapshot', 80)->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->char('token_hash', 64)->nullable()->unique();
            $table->text('token_ciphertext')->nullable();
            $table->unsignedInteger('delivery_version')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('failure_code', 160)->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['invited_by', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
