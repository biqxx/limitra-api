<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_sources', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 100)->default('direct')->index();
            $table->string('medium', 100)->nullable();
            $table->string('campaign', 255)->nullable();
            $table->string('referrer_url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_sources');
    }
};
