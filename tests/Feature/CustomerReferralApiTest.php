<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Referral\CustomerReferral;
use App\Models\Referral\CustomerReferralAttribution;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Referral\CustomerReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerReferralApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'app.frontend_url' => 'https://shop.example.test',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_customer_gets_stable_code_policy_counts_and_wallet_summary(): void
    {
        $customer = $this->user('referrer@example.com');
        $first = $this->actingAs($customer, 'api')->getJson('/api/v1/referrals/me')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.reward.amount', '7000.00')
            ->assertJsonPath('data.reward.amount_minor', 700000)
            ->assertJsonPath('data.reward.currency', 'NGN')
            ->assertJsonPath('data.lim_cash_balance', '0.00')
            ->assertJsonPath('data.counts.total', 0);

        $code = $first->json('data.code');
        $this->assertStringStartsWith('LIM-', $code);
        $this->assertSame("https://shop.example.test/ref/{$code}", $first->json('data.share_url'));
        $this->actingAs($customer, 'api')->getJson('/api/v1/referrals/me')->assertJsonPath('data.code', $code);
    }

    public function test_public_resolution_returns_privacy_safe_token_and_hashes_session_data(): void
    {
        $referrer = $this->user('resolve-referrer@example.com');
        $code = app(CustomerReferralService::class)->codeFor($referrer)->code;
        $session = 'browser-session-000001';

        $this->postJson('/api/v1/referrals/resolve', ['code' => mb_strtolower($code), 'session_id' => $session])
            ->assertOk()
            ->assertJsonPath('data.type', 'customer')
            ->assertJsonPath('data.code', $code)
            ->assertJsonPath('data.cookie.name', 'limitra_referral')
            ->assertJsonMissing(['email' => $referrer->email]);

        $attribution = CustomerReferralAttribution::query()->firstOrFail();
        $this->assertNotSame($session, $attribution->session_hash);
        $this->assertSame(64, strlen($attribution->session_hash));
        $this->assertNotNull($attribution->ip_hash);
    }

    public function test_signup_with_customer_referral_token_creates_one_pending_referral(): void
    {
        $referrer = $this->user('token-referrer@example.com');
        $code = app(CustomerReferralService::class)->codeFor($referrer)->code;
        $token = $this->postJson('/api/v1/referrals/resolve', [
            'code' => $code,
            'session_id' => 'signup-session-000001',
        ])->assertOk()->json('data.token');
        Notification::fake();

        $this->postJson('/api/v1/signup', $this->signupPayload('referred@example.com', [
            'referral_token' => $token,
        ]))->assertCreated();

        $referred = User::query()->where('email', 'referred@example.com')->firstOrFail();
        $this->assertDatabaseHas('customer_referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'status' => 'pending',
        ]);
        $this->assertNotNull(CustomerReferralAttribution::query()->firstOrFail()->converted_at);
        Notification::assertSentTo($referred, VerifyEmailNotification::class);

        $this->postJson('/api/v1/signup', $this->signupPayload('second-referred@example.com', [
            'referral_token' => $token,
        ]))->assertUnprocessable()->assertJsonValidationErrors('referral_token');
        $this->assertDatabaseCount('customer_referrals', 1);
    }

    public function test_direct_customer_code_signup_and_referrer_history_are_owner_scoped(): void
    {
        $referrer = $this->user('direct-referrer@example.com');
        $other = $this->user('direct-other@example.com');
        $code = app(CustomerReferralService::class)->codeFor($referrer)->code;
        Notification::fake();

        $this->postJson('/api/v1/signup', $this->signupPayload('direct-referred@example.com', [
            'referral_code' => $code,
        ]))->assertCreated();

        $referral = CustomerReferral::query()->firstOrFail();
        $this->actingAs($referrer, 'api')->getJson('/api/v1/referrals/me?status=pending')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $referral->id)
            ->assertJsonPath('data.counts.pending', 1);
        $this->actingAs($other, 'api')->getJson('/api/v1/referrals/me')
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_existing_affiliate_referral_signup_still_works(): void
    {
        $affiliateUser = $this->user('affiliate-referrer@example.com', 'affiliate');
        $affiliate = Affiliate::query()->create([
            'user_id' => $affiliateUser->id,
            'slug' => 'affiliate-referrer',
            'code' => 'AFFCODE1',
            'commission_rate' => '5.00',
            'status' => 'active',
        ]);
        Notification::fake();

        $this->postJson('/api/v1/signup', $this->signupPayload('affiliate-referred@example.com', [
            'referral_code' => $affiliate->code,
        ]))->assertCreated();

        $referred = User::query()->where('email', 'affiliate-referred@example.com')->firstOrFail();
        $this->assertSame($affiliate->id, $referred->referred_by);
        $this->assertDatabaseHas('affiliate_referrals', [
            'affiliate_id' => $affiliate->id,
            'user_id' => $referred->id,
        ]);
        $this->assertDatabaseCount('customer_referrals', 0);
    }

    private function signupPayload(string $email, array $extra = []): array
    {
        return array_merge([
            'name' => 'Referred Customer',
            'username' => str($email)->before('@')->replaceMatches('/[^A-Za-z0-9_]/', '_')->toString(),
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $extra);
    }

    private function user(string $email, string $role = 'user'): User
    {
        return User::query()->create([
            'username' => str($email)->before('@').'-'.fake()->unique()->numberBetween(100, 999),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
