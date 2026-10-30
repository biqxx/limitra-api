<?php

namespace Tests\Feature\Wallet;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Payment\Account;
use App\Models\User;
use App\Services\Payment\PaystackService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WalletDepositApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_customer_can_initialize_and_verify_wallet_deposit(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $account = Account::create(['user_id' => $user->id, 'currency' => 'NGN', 'balance' => 0]);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('initializeTransaction')
            ->once()
            ->andReturn([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/auth-dep-123',
                    'access_code' => 'access-dep-123',
                ],
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        // 1. Initialize deposit
        $initResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 5000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertCreated()
            ->assertJsonPath('data.deposit.status', 'pending')
            ->assertJsonPath('data.deposit.amount_minor', 500000)
            ->assertJsonPath('data.deposit.authorization_url', 'https://checkout.paystack.com/auth-dep-123');

        $reference = $initResponse->json('data.deposit.reference');

        $this->assertDatabaseHas('wallet_deposits', [
            'reference' => $reference,
            'user_id' => $user->id,
            'amount_minor' => 500000,
            'status' => 'pending',
        ]);

        // 2. Verify deposit
        $paystack->shouldReceive('verifyTransaction')
            ->with($reference)
            ->once()
            ->andReturn([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'id' => 998877,
                ],
            ]);

        $verifyResponse = $this->actingAs($user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.deposit.status', 'completed');

        $this->assertDatabaseHas('wallet_deposits', [
            'reference' => $reference,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'account_id' => $account->id,
            'type' => 'deposit',
            'amount_minor' => 500000,
            'direction' => 'credit',
        ]);

        // Account balance is updated to 500,000 minor units (5,000.00)
        $account->refresh();
        $this->assertSame(500000, (int) $account->cash_balance_minor);

        // 3. Re-verify is idempotent
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('message', 'Deposit already verified.');

        // Account balance was not double-credited
        $account->refresh();
        $this->assertSame(500000, (int) $account->cash_balance_minor);
    }

    public function test_cannot_deposit_below_minimum_or_above_maximum(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);

        // Below minimum (minimum is 100 NGN = 10000 kobo)
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 5.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // Above maximum (maximum is 1,000,000 NGN = 100000000 kobo)
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 2000000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_cannot_deposit_when_deposits_disabled(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);

        $settings = Mockery::mock(BusinessSettingsService::class);
        $settings->shouldReceive('value')
            ->with('wallet.deposits_enabled', true)
            ->andReturn(false);
        $this->app->instance(BusinessSettingsService::class, $settings);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 500.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'WALLET_DEPOSITS_DISABLED');
    }
}
