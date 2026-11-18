<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microstores', function (Blueprint $table): void {
            $table->string('type', 30)->default('microstore')->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('microstores', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};

