<?php

namespace Database\Factories\Admin;

use App\Models\Admin\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'action' => fake()->randomElement([
                'business_setting.updated',
                'inventory.adjusted',
                'user.suspended',
            ]),
            'subject_type' => null,
            'subject_id' => null,
            'reason' => fake()->optional()->sentence(),
            'before_values' => ['status' => 'pending'],
            'after_values' => ['status' => 'approved'],
            'metadata' => ['source' => 'factory'],
            'request_id' => fake()->uuid(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
