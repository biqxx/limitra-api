<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Services\Payment\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_customer_wallet_defaults_to_zero_and_exposes_database_backed_policy(): void
    {
        $user = $this->user('wallet-empty@example.com');

        $this->actingAs($user, 'api')->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.balances.cash', '0.00')
            ->assertJsonPath('data.balances.lim_cash', '0.00')
            ->assertJsonPath('data.balances.total', '0.00')
            ->assertJsonPath('data.policy.deposits_enabled', true)
            ->assertJsonPath('data.policy.withdrawals_enabled', false)
            ->assertJsonPath('data.policy.minimum_deposit_minor', 10000)
            ->assertJsonPath('data.policy.maximum_deposit_minor', 100000000);
    }

    public function test_ledger_posts_idempotent_credits_and_debits_and_wallet_uses_ledger_totals(): void
    {
        $user = $this->user('wallet-ledger@example.com');
        $ledger = app(WalletLedgerService::class);

        $credit = $ledger->credit(
            $user,
            700000,
            'lim_cash',
            'referral_reward',
            'referral_reward:1',
            'Referral reward',
            'customer_referral',
            1,
        );
        $replay = $ledger->credit(
            $user,
            700000,
            'lim_cash',
            'referral_reward',
            'referral_reward:1',
            'Referral reward',
            'customer_referral',
            1,
        );
        $debit = $ledger->debit(
            $user,
            200000,
            'lim_cash',
            'purchase',
            'purchase:1',
            'Order purchase',
            'order',
            1,
        );

        $this->assertSame($credit->id, $replay->id);
        $this->assertSame(500000, $debit->balance_after_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(500000, $user->account()->value('lim_cash_balance_minor'));

        $this->actingAs($user, 'api')->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balances.lim_cash', '5000.00')
            ->assertJsonPath('data.balances.lim_cash_minor', 500000);

        $this->actingAs($user, 'api')->getJson('/api/v1/wallet/transactions?balance_type=lim_cash')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.type', 'purchase')
            ->assertJsonPath('data.items.0.amount', '2000.00')
            ->assertJsonPath('data.items.1.type', 'referral_reward');
    }

    public function test_insufficient_debit_does_not_create_a_ledger_entry(): void
    {
        $user = $this->user('wallet-insufficient@example.com');

        try {
            app(WalletLedgerService::class)->debit(
                $user,
                100,
                'cash',
                'purchase',
                'purchase:insufficient',
                'Order purchase',
            );
            $this->fail('Expected an insufficient-balance validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_wallet_transactions_are_owner_scoped_and_filterable(): void
    {
        $owner = $this->user('wallet-owner@example.com');
        $other = $this->user('wallet-other@example.com');
        $ledger = app(WalletLedgerService::class);
        $ledger->credit($owner, 10000, 'cash', 'deposit', 'deposit:owner', 'Owner deposit');
        $ledger->credit($other, 20000, 'cash', 'deposit', 'deposit:other', 'Other deposit');

        $this->actingAs($owner, 'api')->getJson('/api/v1/wallet/transactions?type=deposit')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.amount_minor', 10000);
    }

    public function test_customer_cannot_use_legacy_direct_balance_mutations(): void
    {
        $customer = $this->user('wallet-direct@example.com');
        $account = app(WalletLedgerService::class)->accountFor($customer);

        $this->actingAs($customer, 'api')->postJson("/api/v1/accounts/{$account->id}/deposit", [
            'amount' => '1000.00',
            'reason' => 'Self credit',
        ], ['Idempotency-Key' => 'self-credit-001'])->assertForbidden();
    }

    public function test_admin_adjustments_are_idempotent_ledger_entries(): void
    {
        $customer = $this->user('wallet-adjusted@example.com');
        $admin = $this->user('wallet-admin@example.com', 'admin');
        $account = app(WalletLedgerService::class)->accountFor($customer);
        $payload = ['amount' => '1500.00', 'reason' => 'Approved service credit', 'balance_type' => 'lim_cash'];

        $this->actingAs($admin, 'api')->postJson("/api/v1/accounts/{$account->id}/deposit", $payload, [
            'Idempotency-Key' => 'manual-credit-001',
        ])->assertOk()->assertJsonPath('data.lim_cash_balance', '1500.00');
        $this->actingAs($admin, 'api')->postJson("/api/v1/accounts/{$account->id}/deposit", $payload, [
            'Idempotency-Key' => 'manual-credit-001',
        ])->assertOk()->assertJsonPath('data.lim_cash_balance', '1500.00');

        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_posted_ledger_entries_cannot_be_updated_or_deleted(): void
    {
        $user = $this->user('wallet-immutable@example.com');
        $transaction = app(WalletLedgerService::class)->credit(
            $user,
            10000,
            'cash',
            'adjustment',
            'immutable:1',
            'Immutable test credit',
        );

        try {
            $transaction->update(['description' => 'Changed']);
            $this->fail('Expected immutable transaction update to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Posted wallet transactions are immutable.', $exception->getMessage());
        }

        try {
            $transaction->delete();
            $this->fail('Expected immutable transaction deletion to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Posted wallet transactions cannot be deleted.', $exception->getMessage());
        }

        $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id]);
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
