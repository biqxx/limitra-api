<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Reward\RewardCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRewardCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_create_list_and_update_a_reward_campaign(): void
    {
        $admin = $this->user('admin');
        $response = $this->actingAs($admin, 'api')->postJson(
            '/api/v1/admin/reward-campaigns',
            $this->campaignPayload(),
        )->assertCreated()
            ->assertJsonPath('data.name', 'Launch Spin')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.prizes.0.value_minor', 700000)
            ->assertJsonPath('data.prizes.0.weight_basis_points', 1000000);

        $campaignId = $response->json('data.id');
        $prizeId = $response->json('data.prizes.0.id');
        $this->assertDatabaseHas('reward_campaigns', ['public_id' => $campaignId, 'created_by' => $admin->id]);
        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/reward-campaigns?status=active')
            ->assertOk()->assertJsonCount(1, 'data.items');

        $payload = $this->campaignPayload([
            'name' => 'Updated Spin',
            'prizes' => [[
                'id' => $prizeId,
                'label' => 'Win 7,000',
                'type' => 'cash',
                'value' => '7000.00',
                'weight' => '75.0000',
                'inventory_limit' => 50,
            ], [
                'label' => 'Try Again',
                'type' => 'no_reward',
                'value' => '0.00',
                'weight' => '25.0000',
            ]],
        ]);

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/reward-campaigns/{$campaignId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Spin')
            ->assertJsonPath('data.version', 2)
            ->assertJsonCount(2, 'data.prizes');
    }

    public function test_only_admin_can_manage_reward_campaigns(): void
    {
        foreach (['staff', 'user'] as $role) {
            $this->actingAs($this->user($role), 'api')
                ->postJson('/api/v1/admin/reward-campaigns', $this->campaignPayload())
                ->assertForbidden();
        }
    }

    public function test_campaign_validation_rejects_invalid_weights_and_overlapping_active_periods(): void
    {
        $admin = $this->user('admin');
        $invalid = $this->campaignPayload(['prizes' => [[
            'label' => 'Impossible Prize',
            'type' => 'cash',
            'value' => '100.00',
            'weight' => '100.00',
        ], [
            'label' => 'Another Prize',
            'type' => 'cash',
            'value' => '100.00',
            'weight' => '1.00',
        ]]]);

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reward-campaigns', $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors('prizes');
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reward-campaigns', $this->campaignPayload())
            ->assertCreated();
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reward-campaigns', $this->campaignPayload([
            'name' => 'Overlapping Spin',
        ]))->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->assertSame(1, RewardCampaign::query()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function campaignPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Launch Spin',
            'status' => 'active',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addWeek()->toIso8601String(),
            'eligibility' => ['new_accounts_only' => false, 'max_claims_per_user' => 1],
            'coupon_expiry_days' => 7,
            'prizes' => [[
                'label' => 'Win 7,000',
                'type' => 'cash',
                'value' => '7000.00',
                'weight' => '100.0000',
                'inventory_limit' => 100,
            ]],
        ], $overrides);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'username' => Str::lower($role).'-'.Str::random(8),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
