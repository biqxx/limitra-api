<?php

namespace App\Services\Payment;

use App\Models\Payment\Account;
use App\Models\Payment\WalletTransaction;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;

class WalletBalanceService
{
    public function __construct(private readonly BusinessSettingsService $settings) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $account = Account::query()
            ->select(['id', 'currency', 'updated_at'])
            ->where('user_id', $user->id)
            ->first();
        $currency = $account?->currency ?? $this->settings->value('wallet.currency');
        $balances = ['cash' => 0, 'lim_cash' => 0];

        if ($account) {
            WalletTransaction::query()
                ->selectRaw("balance_type, SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) AS balance_minor")
                ->where('account_id', $account->id)
                ->where('status', 'posted')
                ->groupBy('balance_type')
                ->get()
                ->each(function (WalletTransaction $transaction) use (&$balances): void {
                    $balances[$transaction->balance_type] = (int) $transaction->getAttribute('balance_minor');
                });
        }

        return [
            'currency' => $currency,
            'balances' => [
                'cash' => $this->money($balances['cash']),
                'lim_cash' => $this->money($balances['lim_cash']),
                'total' => $this->money($balances['cash'] + $balances['lim_cash']),
                'cash_minor' => $balances['cash'],
                'lim_cash_minor' => $balances['lim_cash'],
                'total_minor' => $balances['cash'] + $balances['lim_cash'],
            ],
            'updated_at' => $account?->updated_at,
        ];
    }

    public function money(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }
}
