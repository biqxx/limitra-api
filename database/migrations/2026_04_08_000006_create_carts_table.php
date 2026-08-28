<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('guest_token_hash', 64)->nullable()->unique();
            $table->timestamp('guest_expires_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->enum('status', ['active', 'checked_out'])->default('active');
            $table->char('currency', 3)->default('NGN');
            $table->timestamps();

            // Only one active cart per user is enforced in application logic;
            // multiple rows are allowed to preserve checkout history.
            $table->index(['user_id', 'status']);
            $table->index(['guest_expires_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
