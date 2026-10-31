<?php

namespace Tests\Feature\Wallet;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Payment\Account;
use App\Models\Payment\PaymentWebhook;
use App\Models\Payment\WalletDeposit;
use App\Models\User;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Payment\PaystackService;
use App\Services\Payment\RefundSettlementService;
use App\Services\Wallet\WalletDepositSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebhookAuthoritativeWalletDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        config(['services.paystack.secret_key' => 'sk_test_paystack_secret_key_12345']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $this->account = Account::create(['user_id' => $this->user->id, 'currency' => 'NGN', 'balance' => 0]);
    }

    public function test_deposit_initialization_supports_idempotency_key_and_replays_safely(): void
    {
        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('initializeTransaction')
            ->once() // Called only once even with 2 requests!
            ->andReturn([
                'authorization_url' => 'https://checkout.paystack.com/auth-dep-replay',
                'access_code' => 'access-dep-replay',
                'reference' => 'WLT-DEP-REPLAY123',
            ]);
        $this->app->instance(PaystackService::class, $paystack);

        // 1. Initial request with Idempotency-Key
        $res1 = $this->actingAs($this->user, 'api')
            ->withHeader('Idempotency-Key', 'idemp-dep-key-001')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 1000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ]);

        $res1->assertCreated()
            ->assertJsonPath('data.deposit.status', 'pending')
            ->assertJsonPath('data.deposit.amount_minor', 100000);

        $reference = $res1->json('data.deposit.reference');

        // 2. Exact repeated request with same Idempotency-Key replays safely
        $res2 = $this->actingAs($this->user, 'api')
            ->withHeader('Idempotency-Key', 'idemp-dep-key-001')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 1000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ]);

        $res2->assertOk()
            ->assertJsonPath('message', 'Deposit initialization replayed.')
            ->assertJsonPath('data.deposit.reference', $reference);

        // Only 1 deposit record was created
        $this->assertEquals(1, WalletDeposit::where('user_id', $this->user->id)->count());
    }

    public function test_deposit_initialization_rejects_reused_idempotency_key_with_different_payload(): void
    {
        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('initializeTransaction')->once()->andReturn([
            'authorization_url' => 'https://checkout.paystack.com/auth-dep-diff',
            'access_code' => 'access-dep-diff',
        ]);
        $this->app->instance(PaystackService::class, $paystack);

        // First call
        $this->actingAs($this->user, 'api')
            ->withHeader('Idempotency-Key', 'idemp-conflict-key')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 2000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertCreated();

        // Second call with different amount but SAME idempotency key must fail
        $this->actingAs($this->user, 'api')
            ->withHeader('Idempotency-Key', 'idemp-conflict-key')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 5000.00,
                'callback_url' => 'https://limitra.test/wallet/callback',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_deposit_initialization_rejects_unauthorized_callback_domains(): void
    {
        // Malicious open redirect domain
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/wallet/deposits/initialize', [
                'amount' => 1000.00,
                'callback_url' => 'https://phishing-attacker.com/steal-creds',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('callback_url')
            ->assertJsonPath('errors.callback_url.0', 'The callback URL host [phishing-attacker.com] is not an authorized Limitra frontend origin.');
    }

    public function test_client_verification_cannot_credit_wallet_authoritatively(): void
    {
        $deposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-GATEWAY-ONLY',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => 'pending',
            'method' => 'card',
            'expires_at' => now()->addDay(),
        ]);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')
            ->with($deposit->reference)
            ->andReturn([
                'status' => 'success',
                'id' => 112233,
                'reference' => $deposit->reference,
            ]);
        $this->app->instance(PaystackService::class, $paystack);

        // Customer calls verification
        $res = $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$deposit->reference}/verify");

        $res->assertOk()
            ->assertJsonPath('data.deposit.status', 'pending')
            ->assertJsonPath('message', 'Payment confirmed by gateway; awaiting webhook settlement.');

        // Crucial security assertion: Wallet balance remains 0!
        $this->account->refresh();
        $this->assertSame(0, (int) $this->account->cash_balance_minor);
        $this->assertDatabaseMissing('wallet_transactions', ['account_id' => $this->account->id]);
    }

    public function test_paystack_webhook_authoritatively_settles_deposit_and_credits_wallet(): void
    {
        $deposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-WEBHOOK-AUTH',
            'amount_minor' => 250000,
            'currency' => 'NGN',
            'status' => 'pending',
            'method' => 'card',
            'expires_at' => now()->addDay(),
        ]);

        $webhookPayload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 778899,
                'reference' => $deposit->reference,
                'amount' => 250000,
                'currency' => 'NGN',
                'status' => 'success',
                'metadata' => [
                    'type' => 'wallet_deposit',
                    'user_id' => $this->user->id,
                    'account_id' => $this->account->id,
                ],
            ],
        ];

        $rawPayload = json_encode($webhookPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawPayload, (string) config('services.paystack.secret_key'));

        // Post to webhook endpoint
        $this->postRawWebhook($rawPayload, $signature)->assertOk();

        // Run webhook processing
        $webhook = PaymentWebhook::where('reference', $deposit->reference)->firstOrFail();
        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(PaymentSettlementService::class),
            app(RefundSettlementService::class),
            app(WalletDepositSettlementService::class),
        );

        // Authoritative assertions:
        $deposit->refresh();
        $this->assertSame('completed', $deposit->status);
        $this->assertNotNull($deposit->paid_at);
        $this->assertSame('778899', $deposit->provider_reference);

        $this->account->refresh();
        $this->assertSame(250000, (int) $this->account->cash_balance_minor);

        $this->assertDatabaseHas('wallet_transactions', [
            'account_id' => $this->account->id,
            'type' => 'deposit',
            'amount_minor' => 250000,
            'direction' => 'credit',
        ]);

        // Audit event recorded
        $this->assertDatabaseHas('audit_events', [
            'action' => 'wallet_deposit.completed',
            'actor_id' => $this->user->id,
            'subject_id' => $deposit->id,
        ]);
    }

    public function test_paystack_webhook_with_mismatched_amount_or_user_fails_settlement(): void
    {
        $deposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-TAMPERED',
            'amount_minor' => 500000, // 5,000 NGN
            'currency' => 'NGN',
            'status' => 'pending',
            'method' => 'card',
        ]);

        // Attacker attempts to send webhook with only 100 NGN (10,000 kobo)
        $tamperedPayload = [
            'event' => 'charge.success',
            'data' => [
                'id' => 12345,
                'reference' => $deposit->reference,
                'amount' => 10000, // MISMATCHED AMOUNT!
                'currency' => 'NGN',
                'status' => 'success',
            ],
        ];

        $rawPayload = json_encode($tamperedPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawPayload, (string) config('services.paystack.secret_key'));

        $this->postRawWebhook($rawPayload, $signature);

        $webhook = PaymentWebhook::where('reference', $deposit->reference)->firstOrFail();
        $this->assertSame('failed', $webhook->status);
        $this->assertStringContainsString('amount does not match', (string) $webhook->error);

        // Wallet was NOT credited!
        $this->account->refresh();
        $this->assertSame(0, (int) $this->account->cash_balance_minor);

        // Deposit was NOT completed
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->paid_at);
        $this->assertSame(0, (int) $this->account->cash_balance_minor);
    }

    public function test_access_code_and_metadata_are_hidden_from_default_model_serialization(): void
    {
        $deposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-SERIALIZE-TEST',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => 'pending',
            'method' => 'card',
            'authorization_url' => 'https://checkout.paystack.com/auth-secret',
            'access_code' => 'SUPER_SECRET_ACCESS_CODE_999',
            'metadata' => ['provider_token' => 'hidden_token'],
        ]);

        $serialized = $deposit->toArray();

        $this->assertArrayNotHasKey('access_code', $serialized);
        $this->assertArrayNotHasKey('metadata', $serialized);
        $this->assertArrayNotHasKey('request_hash', $serialized);

        // Verification endpoint also does not expose access code
        $res = $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/wallet/deposits/{$deposit->reference}/verify");

        $res->assertOk()
            ->assertJsonMissing(['access_code' => 'SUPER_SECRET_ACCESS_CODE_999']);
    }

    public function test_pruning_expired_and_abandoned_wallet_deposits(): void
    {
        // Expired deposit
        $expiredDeposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-OLD-001',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => 'pending',
            'expires_at' => now()->subHours(2),
        ]);

        // Still active deposit
        $activeDeposit = WalletDeposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'reference' => 'WLT-DEP-ACTIVE-002',
            'amount_minor' => 100000,
            'currency' => 'NGN',
            'status' => 'pending',
            'expires_at' => now()->addHours(20),
        ]);

        $this->artisan('wallet:reconcile-deposits')
            ->assertSuccessful();

        $this->assertSame('expired', $expiredDeposit->fresh()->status);
        $this->assertSame('pending', $activeDeposit->fresh()->status);
    }

    public function test_customer_wallet_withdrawals_remain_disabled_by_policy(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.policy.withdrawals_enabled', false);
    }

    private function postRawWebhook(string $payload, string $signature)
    {
        return $this->call('POST', '/api/v1/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload);
    }
}
