<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Reward\RewardCampaign;
use App\Models\Reward\RewardPrize;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpinRewardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_eligible_customer_wins_cash_once_and_idempotent_replay_does_not_credit_twice(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $campaign = $this->campaign($admin, maxClaims: 1);
        $this->prize($campaign, 'cash', 700000, inventoryLimit: 10);

        $first = $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-request-0001'],
        )->assertCreated()
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.reward.type', 'lim_cash')
            ->assertJsonPath('data.reward.amount_minor', 700000)
            ->assertJsonPath('data.reward.credited', true);

        $spinId = $first->json('data.id');
        $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-request-0001'],
        )->assertOk()->assertJsonPath('data.id', $spinId);

        $this->assertDatabaseCount('reward_spins', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'spin_reward',
            'balance_type' => 'lim_cash',
            'direction' => 'credit',
            'amount_minor' => 700000,
            'source_type' => 'reward_spin',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 1);

        $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-request-0002'],
        )->assertUnprocessable()->assertJsonValidationErrors('eligibility');
        $this->actingAs($customer, 'api')->getJson('/api/v1/rewards')
            ->assertOk()->assertJsonPath('data.campaign.can_spin', false)->assertJsonCount(1, 'data.items');
        $this->actingAs($customer, 'api')->postJson("/api/v1/rewards/{$spinId}/redeem")
            ->assertOk()->assertJsonPath('message', 'Cash rewards are credited automatically.');
    }

    public function test_spin_requires_an_idempotency_key_and_active_enabled_campaign(): void
    {
        $customer = $this->user('user');

        $this->actingAs($customer, 'api')->postJson('/api/v1/rewards/spin')
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-request-none'],
        )->assertUnprocessable()->assertJsonValidationErrors('campaign');

        BusinessSetting::query()->where('key', 'rewards.enabled')->update(['value' => 'false']);
        Cache::forget('business_settings.values.v1');
        $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-request-disabled'],
        )->assertUnprocessable()->assertJsonValidationErrors('rewards');
    }

    public function test_no_reward_prize_records_the_outcome_without_touching_the_wallet(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $campaign = $this->campaign($admin);
        $this->prize($campaign, 'no_reward', 0);

        $this->actingAs($customer, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'spin-no-reward-01'],
        )->assertCreated()
            ->assertJsonPath('data.status', 'no_reward')
            ->assertJsonPath('data.prize.type', 'no_reward')
            ->assertJsonPath('data.reward', null);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_exhausted_inventory_is_not_over_awarded(): void
    {
        $admin = $this->user('admin');
        $campaign = $this->campaign($admin);
        $prize = $this->prize($campaign, 'cash', 10000, inventoryLimit: 1);

        $this->actingAs($this->user('user'), 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'inventory-spin-01'],
        )->assertCreated()->assertJsonPath('data.status', 'won');
        $this->actingAs($this->user('user'), 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'inventory-spin-02'],
        )->assertCreated()->assertJsonPath('data.status', 'no_reward');

        $this->assertSame(1, $prize->fresh()->inventory_awarded);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_reward_history_and_redemption_are_owner_scoped_and_admin_can_list_wins(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('user');
        $other = $this->user('user');
        $campaign = $this->campaign($admin);
        $this->prize($campaign, 'cash', 25000);
        $spinId = $this->actingAs($owner, 'api')->postJson(
            '/api/v1/rewards/spin', [], ['Idempotency-Key' => 'owner-spin-0001'],
        )->assertCreated()->json('data.id');

        $this->actingAs($other, 'api')->getJson('/api/v1/rewards')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAs($other, 'api')->postJson("/api/v1/rewards/{$spinId}/redeem")->assertForbidden();
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/reward-campaigns/{$campaign->public_id}/wins?status=won")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.user.id', $owner->id);
    }

    private function campaign(User $admin, int $maxClaims = 1): RewardCampaign
    {
        return RewardCampaign::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Test Spin',
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'eligibility' => ['new_accounts_only' => false, 'max_claims_per_user' => $maxClaims],
            'coupon_expiry_days' => 7,
            'version' => 1,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    private function prize(
        RewardCampaign $campaign,
        string $type,
        int $valueMinor,
        ?int $inventoryLimit = null,
    ): RewardPrize {
        return $campaign->prizes()->create([
            'label' => $type === 'cash' ? 'Cash Prize' : 'Try Again',
            'type' => $type,
            'value_minor' => $valueMinor,
            'weight_basis_points' => 1000000,
            'inventory_limit' => $inventoryLimit,
            'inventory_awarded' => 0,
            'active' => true,
            'sort_order' => 0,
        ]);
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
