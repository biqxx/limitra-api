<?php

namespace App\Services\Payment;

use App\Models\Payment\Account;
use App\Models\Payment\WalletTransaction;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class WalletLedgerService
{
    private const BALANCE_TYPES = ['cash', 'lim_cash'];

    private const TYPES = [
        'deposit',
        'withdrawal',
        'referral_reward',
        'spin_reward',
        'purchase',
        'refund',
        'adjustment',
        'reversal',
    ];

    public function __construct(private readonly BusinessSettingsService $settings) {}

    /** @param array<string, mixed> $metadata */
    public function credit(
        User $user,
        int $amountMinor,
        string $balanceType,
        string $type,
        string $uniqueKey,
        string $description,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        return $this->post(
            $user,
            'credit',
            $amountMinor,
            $balanceType,
            $type,
            $uniqueKey,
            $description,
            $sourceType,
            $sourceId,
            $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function debit(
        User $user,
        int $amountMinor,
        string $balanceType,
        string $type,
        string $uniqueKey,
        string $description,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        return $this->post(
            $user,
            'debit',
            $amountMinor,
            $balanceType,
            $type,
            $uniqueKey,
            $description,
            $sourceType,
            $sourceId,
            $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function reverseCredit(
        WalletTransaction $original,
        string $uniqueKey,
        string $description,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        $original = WalletTransaction::query()->with('account.user')->findOrFail($original->id);
        if ($original->direction !== 'credit' || $original->status !== 'posted') {
            throw new InvalidArgumentException('Only a posted wallet credit can be reversed.');
        }

        return $this->post(
            $original->account->user,
            'debit',
            $original->amount_minor,
            $original->balance_type,
            'reversal',
            $uniqueKey,
            $description,
            $sourceType,
            $sourceId,
            $metadata,
            $original->id,
            true,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function reverseDebit(
        WalletTransaction $original,
        string $uniqueKey,
        string $description,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        $original = WalletTransaction::query()->with('account.user')->findOrFail($original->id);
        if ($original->direction !== 'debit' || $original->status !== 'posted') {
            throw new InvalidArgumentException('Only a posted wallet debit can be reversed.');
        }

        return $this->post(
            $original->account->user,
            'credit',
            $original->amount_minor,
            $original->balance_type,
            'refund',
            $uniqueKey,
            $description,
            $sourceType,
            $sourceId,
            $metadata,
            $original->id,
        );
    }

    public function accountFor(User $user): Account
    {
        return Account::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => $this->settings->value('wallet.currency')],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function post(
        User $user,
        string $direction,
        int $amountMinor,
        string $balanceType,
        string $type,
        string $uniqueKey,
        string $description,
        ?string $sourceType,
        ?int $sourceId,
        array $metadata,
        ?int $reversesTransactionId = null,
        bool $allowNegativeBalance = false,
    ): WalletTransaction {
        $this->validateEntry($amountMinor, $balanceType, $type, $uniqueKey, $description);
        $accountId = $this->accountFor($user)->id;

        return DB::transaction(function () use (
            $accountId,
            $direction,
            $amountMinor,
            $balanceType,
            $type,
            $uniqueKey,
            $description,
            $sourceType,
            $sourceId,
            $metadata,
            $reversesTransactionId,
            $allowNegativeBalance,
        ): WalletTransaction {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $existing = WalletTransaction::query()->where('unique_key', $uniqueKey)->first();
            if ($existing) {
                $this->assertReplayMatches(
                    $existing,
                    $account,
                    $direction,
                    $amountMinor,
                    $balanceType,
                    $type,
                    $reversesTransactionId,
                );

                return $existing;
            }

            if ($reversesTransactionId) {
                $original = WalletTransaction::query()->whereKey($reversesTransactionId)->firstOrFail();
                if (
                    $original->account_id !== $account->id
                    || $original->direction === $direction
                    || $original->status !== 'posted'
                    || $original->amount_minor !== $amountMinor
                    || $original->balance_type !== $balanceType
                ) {
                    throw new LogicException('The wallet reversal does not match the original credit.');
                }

                $existingReversal = WalletTransaction::query()
                    ->where('reverses_transaction_id', $reversesTransactionId)
                    ->first();
                if ($existingReversal) {
                    $this->assertReplayMatches(
                        $existingReversal,
                        $account,
                        $direction,
                        $amountMinor,
                        $balanceType,
                        $type,
                        $reversesTransactionId,
                    );

                    return $existingReversal;
                }
            }

            $currentBalance = (int) (WalletTransaction::query()
                ->where('account_id', $account->id)
                ->where('balance_type', $balanceType)
                ->where('status', 'posted')
                ->latest('id')
                ->value('balance_after_minor') ?? 0);

            if ($direction === 'debit' && ! $allowNegativeBalance && $currentBalance < $amountMinor) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient wallet balance.'],
                ]);
            }

            $balanceAfter = $direction === 'credit'
                ? $currentBalance + $amountMinor
                : $currentBalance - $amountMinor;

            $transaction = WalletTransaction::query()->create([
                'reference' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => $type,
                'balance_type' => $balanceType,
                'direction' => $direction,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $balanceAfter,
                'currency' => $account->currency,
                'status' => 'posted',
                'unique_key' => $uniqueKey,
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reverses_transaction_id' => $reversesTransactionId,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);

            $account->forceFill([
                $balanceType === 'cash' ? 'cash_balance_minor' : 'lim_cash_balance_minor' => $balanceAfter,
            ])->save();

            return $transaction;
        }, 3);
    }

    private function validateEntry(
        int $amountMinor,
        string $balanceType,
        string $type,
        string $uniqueKey,
        string $description,
    ): void {
        if ($amountMinor < 1) {
            throw new InvalidArgumentException('Wallet transaction amount must be positive.');
        }
        if (! in_array($balanceType, self::BALANCE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported wallet balance type.');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported wallet transaction type.');
        }
        if ($uniqueKey === '' || mb_strlen($uniqueKey) > 160) {
            throw new InvalidArgumentException('A valid wallet transaction unique key is required.');
        }
        if ($description === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('A valid wallet transaction description is required.');
        }
    }

    private function assertReplayMatches(
        WalletTransaction $existing,
        Account $account,
        string $direction,
        int $amountMinor,
        string $balanceType,
        string $type,
        ?int $reversesTransactionId,
    ): void {
        if (
            $existing->account_id !== $account->id
            || $existing->direction !== $direction
            || $existing->amount_minor !== $amountMinor
            || $existing->balance_type !== $balanceType
            || $existing->type !== $type
            || $existing->reverses_transaction_id !== $reversesTransactionId
        ) {
            throw new LogicException('Wallet transaction unique key was reused with different details.');
        }
    }
}
