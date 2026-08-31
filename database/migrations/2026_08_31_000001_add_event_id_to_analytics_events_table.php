<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('analytics_events', 'event_id')) {
            return;
        }

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->uuid('event_id')->nullable()->after('id');
        });

        DB::table('analytics_events')
            ->whereNull('event_id')
            ->orderBy('id')
            ->eachById(function (object $event): void {
                DB::table('analytics_events')
                    ->where('id', $event->id)
                    ->update(['event_id' => (string) Str::uuid()]);
            });

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->uuid('event_id')->nullable(false)->change();
            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('analytics_events', 'event_id')) {
            return;
        }

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
