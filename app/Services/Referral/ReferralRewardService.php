<?php

namespace App\Services\Referral;

use App\Models\Order\Order;
use App\Models\Referral\CustomerReferral;
use App\Models\User;
use App\Notifications\ReferralRewardEarnedNotification;
use App\Services\Payment\WalletLedgerService;
use App\Services\Settings\BusinessSettingsService;
use Closure;
use Illuminate\Support\Facades\DB;

class ReferralRewardService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly WalletLedgerService $walletLedger,
    ) {}

    public function grantForDeliveredOrder(Order $order): ?CustomerReferral
    {
        return DB::transaction(function () use ($order): ?CustomerReferral {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $this->isEligibleOrder($order) || ! $this->settings->value('referrals.enabled')) {
                return null;
            }

            $referral = CustomerReferral::query()
                ->where('referred_user_id', $order->user_id)
                ->lockForUpdate()
                ->first();

            if (! $referral || $referral->status !== 'pending' || $referral->reward_transaction_id) {
                return $referral;
            }

            $amountMinor = (int) $this->settings->value('referrals.reward_amount_minor');
            if ($amountMinor < 1 || $referral->referrer_id === $referral->referred_user_id) {
                return null;
            }

            $referrer = User::query()->findOrFail($referral->referrer_id);
            $transaction = $this->walletLedger->credit(
                $referrer,
                $amountMinor,
                'lim_cash',
                'referral_reward',
                "referral_reward:{$referral->id}",
                'Reward for a referred customer\'s first delivered order.',
                'customer_referral',
                $referral->id,
                [
                    'referred_user_id' => $referral->referred_user_id,
                    'qualifying_order_id' => $order->id,
                ],
            );
            $rewardedAt = now();

            $referral->forceFill([
                'status' => 'rewarded',
                'qualifying_order_id' => $order->id,
                'reward_transaction_id' => $transaction->id,
                'reward_amount_minor' => $transaction->amount_minor,
                'reward_currency' => $transaction->currency,
                'policy_snapshot' => [
                    'reward_amount_minor' => $amountMinor,
                    'reward_currency' => (string) $this->settings->value('referrals.currency'),
                    'qualification' => 'first_genuine_delivered_order',
                ],
                'qualified_at' => $rewardedAt,
                'rewarded_at' => $rewardedAt,
            ])->save();

            $this->afterCommit(function () use ($referrer, $transaction): void {
                $recipient = User::query()->find($referrer->id);
                $recipient?->notify(new ReferralRewardEarnedNotification(
                    $transaction->amount_minor,
                    $transaction->currency,
                ));
            });

            return $referral->fresh();
        }, 3);
    }

    private function isEligibleOrder(Order $order): bool
    {
        if ($order->status !== 'delivered' || $order->fulfilment_status !== 'delivered') {
            return false;
        }

        if ((float) $order->total_amount <= 0 || ! $order->items()->exists()) {
            return false;
        }

        return $order->payment_method === 'cash_on_delivery' || $order->payment_status === 'paid';
    }

    private function afterCommit(Closure $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
