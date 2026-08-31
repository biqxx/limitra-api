<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('refunds', function (Blueprint $table) {
                $table->foreignId('return_request_id')->nullable()->change();
            });
        }

        Schema::table('refunds', function (Blueprint $table) {
            $table->string('source', 30)->default('return')->after('method')->index();
            $table->string('automation_key', 100)->nullable()->after('source')->unique();
        });
    }

    public function down(): void
    {
        DB::table('refunds')->whereNull('return_request_id')->delete();

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['automation_key']);
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'automation_key']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('refunds', function (Blueprint $table) {
                $table->foreignId('return_request_id')->nullable(false)->change();
            });
        }
    }
};
