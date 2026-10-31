<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateTierHistory;
use App\Models\Order\Order;
use App\Notifications\Affiliate\AffiliateTierUpgradedNotification;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Support\Facades\DB;

class AffiliateTierService
{
    public function __construct(
        private readonly BusinessSettingsService $settings
    ) {}

    /**
     * Get configured tiers sorted from lowest to highest.
     *
     * @return array<int, array{name: string, min_orders: int, commission_rate: float}>
     */
    public function getTiers(): array
    {
        try {
            $tiers = (array) $this->settings->value('affiliates.tiers');
        } catch (\Throwable) {
            $tiers = [
                ['name' => 'Bronze', 'min_orders' => 0, 'commission_rate' => 5.00],
                ['name' => 'Silver', 'min_orders' => 10, 'commission_rate' => 7.50],
                ['name' => 'Gold', 'min_orders' => 25, 'commission_rate' => 10.00],
                ['name' => 'Platinum', 'min_orders' => 50, 'commission_rate' => 12.50],
            ];
        }

        usort($tiers, fn (array $a, array $b): int => $a['min_orders'] <=> $b['min_orders']);

        return $tiers;
    }

    /**
     * Get rolling qualification window in days.
     */
    public function getQualificationWindowDays(): int
    {
        try {
            return (int) $this->settings->value('affiliates.qualification_window_days');
        } catch (\Throwable) {
            return 30;
        }
    }

    /**
     * Calculate qualifying delivered orders and volume in rolling window.
     *
     * @return array{delivered_orders: int, sales_volume: float}
     */
    public function getRollingPerformance(Affiliate $affiliate): array
    {
        $windowDays = $this->getQualificationWindowDays();
        $cutoff = now()->subDays($windowDays);

        $ordersQuery = Order::query()
            ->whereHas('affiliateSales', function ($q) use ($affiliate): void {
                $q->where('affiliate_id', $affiliate->id);
            })
            ->where('fulfilment_status', 'delivered')
            ->where('created_at', '>=', $cutoff);

        $count = $ordersQuery->count();
        $volume = (float) $ordersQuery->sum('grand_total');

        return [
            'delivered_orders' => $count,
            'sales_volume' => round($volume, 2),
        ];
    }

    /**
     * Evaluate and update affiliate tier if eligible.
     *
     * @return array<string, mixed>
     */
    public function evaluateTier(Affiliate $affiliate): array
    {
        $tiers = $this->getTiers();
        $performance = $this->getRollingPerformance($affiliate);
        $deliveredCount = $performance['delivered_orders'];
        $salesVolume = $performance['sales_volume'];

        $eligibleTier = $tiers[0];
        foreach ($tiers as $tier) {
            if ($deliveredCount >= (int) $tier['min_orders']) {
                $eligibleTier = $tier;
            }
        }

        $currentTierName = $affiliate->current_tier ?: 'Bronze';
        $newTierName = $eligibleTier['name'];
        $newCommissionRate = (float) $eligibleTier['commission_rate'];

        if ($currentTierName !== $newTierName) {
            DB::transaction(function () use ($affiliate, $currentTierName, $newTierName, $newCommissionRate, $deliveredCount, $salesVolume): void {
                /** @var Affiliate $lockedAffiliate */
                $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
                $lockedAffiliate->current_tier = $newTierName;
                $lockedAffiliate->commission_rate = $newCommissionRate;
                $lockedAffiliate->save();

                AffiliateTierHistory::create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'from_tier' => $currentTierName,
                    'to_tier' => $newTierName,
                    'qualifying_delivered_orders_count' => $deliveredCount,
                    'qualifying_sales_volume' => $salesVolume,
                    'reason' => "Delivered {$deliveredCount} orders in {$this->getQualificationWindowDays()}-day rolling window",
                    'created_at' => now(),
                ]);

                if ($lockedAffiliate->user) {
                    $lockedAffiliate->user->notify(new AffiliateTierUpgradedNotification(
                        tier: $newTierName,
                        commissionRate: $newCommissionRate
                    ));
                }
            });

            $affiliate->refresh();
        }

        // Find next tier if any
        $nextTier = null;
        $ordersNeeded = 0;
        foreach ($tiers as $tier) {
            if ((int) $tier['min_orders'] > $deliveredCount) {
                $nextTier = $tier;
                $ordersNeeded = (int) $tier['min_orders'] - $deliveredCount;
                break;
            }
        }

        return [
            'current_tier' => $affiliate->current_tier,
            'commission_rate' => (float) $affiliate->commission_rate,
            'qualifying_delivered_orders' => $deliveredCount,
            'qualifying_sales_volume' => $salesVolume,
            'qualification_window_days' => $this->getQualificationWindowDays(),
            'next_tier' => $nextTier ? $nextTier['name'] : null,
            'next_tier_rate' => $nextTier ? (float) $nextTier['commission_rate'] : null,
            'orders_needed_for_next_tier' => $ordersNeeded,
        ];
    }
}
