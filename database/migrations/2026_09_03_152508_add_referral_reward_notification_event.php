<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('notification_event_settings')->insert([
            $this->event('referral.reward_earned', 'Referral reward earned', $now),
            $this->event('referral.reward_reversed', 'Referral reward reversed', $now),
        ]);
    }

    public function down(): void
    {
        $events = ['referral.reward_earned', 'referral.reward_reversed'];
        DB::table('notification_preferences')->whereIn('event', $events)->delete();
        DB::table('notification_event_settings')->whereIn('event', $events)->delete();
    }

    /** @return array<string, mixed> */
    private function event(string $event, string $label, mixed $now): array
    {
        return [
            'event' => $event,
            'label' => $label,
            'category' => 'rewards',
            'available_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'default_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'required_channels' => json_encode(['database'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
