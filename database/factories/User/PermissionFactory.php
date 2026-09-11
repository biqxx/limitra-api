<?php

namespace Database\Factories\User;

use App\Models\User\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2, '.'),
            'domain' => fake()->word(),
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }
}
