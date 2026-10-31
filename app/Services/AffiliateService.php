<?php

namespace App\Services;

use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateReferral;
use App\Models\Order\Order;
use App\Services\Affiliate\AffiliateAttributionService;

class AffiliateService
{
    public function __construct(
        private readonly AffiliateAttributionService $attributionService
    ) {}

    /**
     * Record affiliate sales, commissions, ledger entries, and link conversion
     * using deterministic precedence: Promo Code > Tracked Link/Cookie > Referral.
     */
    public function recordSales(Order $order, ?string $affiliateCode = null, ?string $cookieToken = null): void
    {
        $this->attributionService->attributeConversion($order, $affiliateCode, $cookieToken);
    }

    /**
     * Reverse commissions for a cancelled or refunded order with compensating ledger records.
     */
    public function reverseCommissionsForOrder(Order $order, string $reason): void
    {
        $this->attributionService->reverseCommissionsForOrder($order, $reason);
    }

    /**
     * Record a new user referral when someone signs up with an affiliate code.
     */
    public function recordReferral(int $userId, Affiliate $affiliate): void
    {
        AffiliateReferral::firstOrCreate([
            'affiliate_id' => $affiliate->id,
            'user_id' => $userId,
        ]);
    }
}
