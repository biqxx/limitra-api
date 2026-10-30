<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Social\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_user_can_list_and_disconnect_connected_accounts(): void
    {
        $user = User::factory()->create([
            'username' => 'connected_user',
            'role' => 'user',
            'status' => 'active',
        ]);

        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'whatsapp',
            'platform_sender_id' => '2348011223344',
            'username' => 'WhatsApp User',
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/v1/account/connected-accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.platform', 'whatsapp')
            ->assertJsonPath('data.0.platform_sender_id', '2348011223344');

        $deleteResponse = $this->actingAs($user, 'api')
            ->deleteJson('/api/v1/account/connected-accounts/whatsapp')
            ->assertOk();

        $this->assertDatabaseMissing('social_accounts', [
            'id' => $account->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/account/connected-accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_disconnecting_non_existent_platform_returns_404(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'api')
            ->deleteJson('/api/v1/account/connected-accounts/telegram')
            ->assertNotFound();
    }
}
