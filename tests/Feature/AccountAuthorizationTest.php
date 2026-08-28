<?php

namespace Tests\Feature;

use App\Models\Payment\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
    }

    public function test_regular_user_cannot_list_create_or_delete_accounts(): void
    {
        $user = $this->createUser('user');
        $otherUser = $this->createUser('user');
        $account = Account::create(['user_id' => $otherUser->id]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/accounts')
            ->assertForbidden();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/accounts', ['user_id' => $user->id])
            ->assertForbidden();

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/accounts/{$account->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_staff_can_list_accounts_but_cannot_create_or_delete_them(): void
    {
        $staff = $this->createUser('staff');
        $accountOwner = $this->createUser('user');
        $account = Account::create(['user_id' => $accountOwner->id]);

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/accounts')
            ->assertOk();

        $this->actingAs($staff, 'api')
            ->postJson('/api/v1/accounts', ['user_id' => $staff->id])
            ->assertForbidden();

        $this->actingAs($staff, 'api')
            ->deleteJson("/api/v1/accounts/{$account->id}")
            ->assertForbidden();
    }

    private function createUser(string $role): User
    {
        return User::create([
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
