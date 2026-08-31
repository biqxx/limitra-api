<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analytics_event_id')->nullable()->constrained('analytics_events')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 64)->index();
            $table->string('url', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('source', 100)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['session_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
