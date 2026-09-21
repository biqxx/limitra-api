<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->longText('delivery_secret')->nullable();
            $table->unsignedInteger('delivery_version')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();

            $table->index(['user_id', 'delivery_version']);
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'delivery_version']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn([
                'delivery_secret',
                'delivery_version',
                'sent_at',
                'failed_at',
                'failure_code',
            ]);
        });
    }
};
