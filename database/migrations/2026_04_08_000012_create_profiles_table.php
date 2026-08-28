<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('avatar')->nullable();   // URL or storage path
            $table->string('phone', 20)->nullable();
            $table->date('birthday')->nullable();
            $table->enum('gender', ['female', 'male', 'non_binary', 'prefer_not_to_say'])->nullable();
            $table->boolean('subscribe_to_newsletter')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
