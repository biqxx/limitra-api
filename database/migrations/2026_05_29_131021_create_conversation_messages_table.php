<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');          // user | assistant | tool
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();   // AI's tool call request
            $table->json('tool_results')->nullable(); // result returned to AI
            $table->json('metadata')->nullable();     // tokens used, model, etc.

            $table->index('conversation_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
