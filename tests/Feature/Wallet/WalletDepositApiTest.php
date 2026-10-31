<?php

namespace Tests\Feature\Wallet;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Payment\Account;
use App\Models\Payment\PaymentWebhook;
use App\Models\User;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Payment\PaystackService;
use App\Services\Payment\RefundSettlementService;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Wallet\WalletDepositSettlementService;
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
                'authorization_url' => 'https://checkout.paystack.com/auth-dep-123',
                'access_code' => 'access-dep-123',
                'reference' => 'test-ref',
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

        // 2. Client verification queries gateway status, but cannot be source of truth for crediting
        $paystack->shouldReceive('verifyTransaction')
            ->with($reference)
            ->once()
            ->andReturn([
                'status' => 'success',
                'id' => 998877,
                'reference' => $reference,
            ]);

        $verifyResponse = $this->actingAs($user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.deposit.status', 'pending')
            ->assertJsonPath('message', 'Payment confirmed by gateway; awaiting webhook settlement.');

        // Wallet was NOT yet credited by client verification
        $account->refresh();
        $this->assertSame(0, (int) $account->cash_balance_minor);

        // 3. Paystack verified webhook arrives authoritatively
        $webhookPayload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 998877,
                'reference' => $reference,
                'amount' => 500000,
                'currency' => 'NGN',
                'status' => 'success',
                'metadata' => [
                    'type' => 'wallet_deposit',
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'amount_minor' => 500000,
                ],
            ],
        ];

        $rawPayload = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawPayload, (string) config('services.paystack.secret_key'));

        $paystack->shouldReceive('hasValidWebhookSignature')
            ->andReturn(true);

        $this->call('POST', '/api/v1/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $rawPayload)
            ->assertOk();

        // Process webhook job
        $webhook = PaymentWebhook::where('reference', $reference)->firstOrFail();
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(PaymentSettlementService::class),
            app(RefundSettlementService::class),
            app(WalletDepositSettlementService::class),
        );

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

        // 4. Client verify now returns completed
        $this->actingAs($user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.deposit.status', 'completed')
            ->assertJsonPath('message', 'Deposit verified and credited.');

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
