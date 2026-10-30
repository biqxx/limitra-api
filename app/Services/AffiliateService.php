<?php

namespace App\Services;

use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateReferral;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AffiliateService
{
    /**
     * Record affiliate sales and commissions for a newly placed order.
     *
     * Logic:
     *  1. Direct  — caller passes an affiliate code (e.g. from ?ref=CODE in the
     *               checkout flow). The affiliate gets credit for every item.
     *  2. Indirect — the buyer was referred by an affiliate (users.referred_by).
     *                Only applied when no direct code is present.
     *
     * Self-referrals (affiliate purchasing own items) are strictly disallowed.
     * Commissions are always "pending" until an admin approves/pays them.
     */
    public function recordSales(Order $order, ?string $affiliateCode = null): void
    {
        // Prevent duplicate execution for the same order.
        if (AffiliateSale::where('order_id', $order->id)->exists()) {
            return;
        }

        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            return;
        }

        $affiliate = $this->resolveAffiliate($order->user_id, $affiliateCode);

        if (! $affiliate) {
            return;
        }

        // Self-referral prevention: an affiliate cannot earn commission on their own purchase.
        if ($affiliate->user_id === $order->user_id) {
            return;
        }

        $saleType = $affiliateCode ? 'direct' : 'indirect';

        DB::transaction(function () use ($order, $affiliate, $saleType): void {
            if (AffiliateSale::where('order_id', $order->id)->lockForUpdate()->exists()) {
                return;
            }

            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $totalCommission = 0.0;

            foreach ($order->items as $item) {
                $saleAmount = $item->price_at_purchase * $item->quantity;

                $sale = AffiliateSale::create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'buyer_user_id' => $order->user_id,
                    'sale_type' => $saleType,
                    'sale_amount' => $saleAmount,
                ]);

                $commissionAmount = round($saleAmount * $lockedAffiliate->commission_rate / 100, 2);

                AffiliateCommission::create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'affiliate_sale_id' => $sale->id,
                    'commission_percent' => $lockedAffiliate->commission_rate,
                    'commission_amount' => $commissionAmount,
                    'payment_status' => 'pending',
                ]);

                $totalCommission += $commissionAmount;
            }

            if ($totalCommission > 0) {
                // Keep running total current so dashboard queries stay fast.
                $lockedAffiliate->increment('total_earnings', $totalCommission);
            }
        });
    }

    /**
     * Record a new user referral when someone signs up with an affiliate code.
     * Safe to call multiple times — silently ignores duplicates.
     */
    public function recordReferral(int $userId, Affiliate $affiliate): void
    {
        AffiliateReferral::firstOrCreate([
            'affiliate_id' => $affiliate->id,
            'user_id' => $userId,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve which active affiliate should receive credit for this order.
     * Direct code takes precedence over indirect (referred_by).
     */
    private function resolveAffiliate(int $buyerUserId, ?string $affiliateCode): ?Affiliate
    {
        if ($affiliateCode) {
            return Affiliate::where('code', $affiliateCode)
                ->where('status', 'active')
                ->first();
        }

        $buyer = User::select('id', 'referred_by')->find($buyerUserId);

        if (! $buyer?->referred_by) {
            return null;
        }

        return Affiliate::where('id', $buyer->referred_by)
            ->where('status', 'active')
            ->first();
    }
}
