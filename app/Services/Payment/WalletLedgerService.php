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
        ): WalletTransaction {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $existing = WalletTransaction::query()->where('unique_key', $uniqueKey)->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $account, $direction, $amountMinor, $balanceType, $type);

                return $existing;
            }

            $currentBalance = (int) (WalletTransaction::query()
                ->where('account_id', $account->id)
                ->where('balance_type', $balanceType)
                ->where('status', 'posted')
                ->latest('id')
                ->value('balance_after_minor') ?? 0);

            if ($direction === 'debit' && $currentBalance < $amountMinor) {
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
    ): void {
        if (
            $existing->account_id !== $account->id
            || $existing->direction !== $direction
            || $existing->amount_minor !== $amountMinor
            || $existing->balance_type !== $balanceType
            || $existing->type !== $type
        ) {
            throw new LogicException('Wallet transaction unique key was reused with different details.');
        }
    }
}
