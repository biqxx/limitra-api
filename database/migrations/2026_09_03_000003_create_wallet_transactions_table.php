<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('balance_type', 20);
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('posted');
            $table->string('unique_key', 160)->unique();
            $table->string('description');
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_transaction_id')
                ->nullable()
                ->unique()
                ->constrained('wallet_transactions')
                ->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'type', 'created_at']);
            $table->index(['account_id', 'balance_type', 'id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
