<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('notification_event_settings')->insert([
            'event' => 'referral.reward_earned',
            'label' => 'Referral reward earned',
            'category' => 'rewards',
            'available_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'default_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'required_channels' => json_encode(['database'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('notification_preferences')->where('event', 'referral.reward_earned')->delete();
        DB::table('notification_event_settings')->where('event', 'referral.reward_earned')->delete();
    }
};
