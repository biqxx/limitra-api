<?php

namespace App\Services\Payment;

use App\Models\Commerce\CheckoutQuote;
use App\Models\Order\Order;
use App\Models\Payment\WalletTransaction;
use Illuminate\Validation\ValidationException;

class WalletCheckoutService
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    /** @return array<int, WalletTransaction> */
    public function debitForOrder(Order $order, CheckoutQuote $quote): array
    {
        $allocations = $this->allocations($quote);
        $user = $order->user()->firstOrFail();
        $transactions = [];

        foreach (['lim_cash', 'cash'] as $balanceType) {
            $amountMinor = $allocations[$balanceType];
            if ($amountMinor < 1) {
                continue;
            }

            try {
                $transactions[] = $this->ledger->debit(
                    $user,
                    $amountMinor,
                    $balanceType,
                    'purchase',
                    "order_wallet_debit:{$order->id}:{$balanceType}",
                    "Wallet credit applied to order {$order->number}.",
                    'order',
                    $order->id,
                    ['checkout_quote_id' => $quote->id],
                );
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'quote_id' => ['The wallet balance changed. Create a new checkout quote.'],
                ]);
            }
        }

        return $transactions;
    }

    /** @return array<int, WalletTransaction> */
    public function refundForOrder(Order $order, string $reason): array
    {
        $transactions = WalletTransaction::query()
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('type', 'purchase')
            ->where('direction', 'debit')
            ->orderBy('id')
            ->get();

        return $transactions->map(fn (WalletTransaction $transaction): WalletTransaction => $this->ledger->reverseDebit(
            $transaction,
            "order_wallet_refund:{$order->id}:{$transaction->balance_type}",
            "Wallet credit returned for order {$order->number}.",
            'order',
            $order->id,
            ['reason' => $reason],
        ))->all();
    }

    /** @return array{lim_cash: int, cash: int} */
    private function allocations(CheckoutQuote $quote): array
    {
        $allocation = $quote->wallet_snapshot['allocation_minor'] ?? [];
        $allocations = [
            'lim_cash' => (int) ($allocation['lim_cash'] ?? 0),
            'cash' => (int) ($allocation['cash'] ?? 0),
        ];
        $allocatedMinor = $allocations['lim_cash'] + $allocations['cash'];

        if ($allocatedMinor !== $this->toMinorUnits($quote->wallet_credit)) {
            throw ValidationException::withMessages([
                'quote_id' => ['The checkout quote wallet allocation is invalid. Create a new quote.'],
            ]);
        }

        return $allocations;
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
