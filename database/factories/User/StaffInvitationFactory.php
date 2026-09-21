<?php

namespace Database\Factories\User;

use App\Enums\StaffInvitationStatus;
use App\Models\User;
use App\Models\User\StaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'public_id' => (string) Str::uuid(),
            'email_hash' => hash('sha256', $email),
            'email_ciphertext' => $email,
            'email_masked' => 's***@example.test',
            'name' => fake()->name(),
            'role_id' => null,
            'role_name_snapshot' => null,
            'invited_by' => User::factory()->admin(),
            'status' => StaffInvitationStatus::Queued,
            'token_hash' => hash('sha256', Str::random(64)),
            'token_ciphertext' => Str::random(64),
            'delivery_version' => 1,
            'expires_at' => now()->addDays(2),
        ];
    }
}
