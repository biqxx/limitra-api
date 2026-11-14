<?php

namespace App\Services\Microstore;

use App\Models\Affiliate\Affiliate;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreEvent;
use App\Models\Microstore\MicrostoreProduct;
use App\Models\Order\Order;
use App\Services\AffiliateService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Support\Facades\DB;

class MicrostoreAttributionService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly AffiliateService $affiliates,
    ) {}

    /**
     * Resolve an active, approved microstore for an order via explicit store reference
     * or recent product click within the attribution window.
     *
     * @param  iterable<int, int>  $productIds
     */
    public function resolveForOrder(
        int $userId,
        array $data = [],
        iterable $productIds = [],
        ?string $sessionId = null,
    ): ?Microstore {
        if (! empty($data['microstore_id'])) {
            $microstore = Microstore::query()
                ->whereKey((int) $data['microstore_id'])
                ->where('status', 'active')
                ->where('moderation_status', 'approved')
                ->first();

            if ($microstore && (int) $microstore->user_id !== $userId) {
                return $microstore;
            }
        }

        if (! empty($data['microstore_slug'])) {
            $microstore = Microstore::query()
                ->where('slug', (string) $data['microstore_slug'])
                ->where('status', 'active')
                ->where('moderation_status', 'approved')
                ->first();

            if ($microstore && (int) $microstore->user_id !== $userId) {
                return $microstore;
            }
        }

        $normalizedProductIds = collect($productIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        if ($normalizedProductIds === []) {
            return null;
        }

        $windowDays = (int) $this->settings->value('microstores.attribution_window_days', 30);
        $cutoff = now()->subDays(max(1, $windowDays));

        $clickEvent = MicrostoreEvent::query()
            ->with('microstore')
            ->where('event_type', 'product_click')
            ->whereIn('product_id', $normalizedProductIds)
            ->where('created_at', '>=', $cutoff)
            ->where(function ($query) use ($userId, $sessionId): void {
                $query->where('user_id', $userId);
                if ($sessionId) {
                    $query->orWhere('session_id', $sessionId);
                }
            })
            ->whereHas('microstore', function ($query) use ($userId): void {
                $query->where('status', 'active')
                    ->where('moderation_status', 'approved')
                    ->where('user_id', '!=', $userId);
            })
            ->latest('id')
            ->first();

        return $clickEvent?->microstore;
    }

    /**
     * Attribute a paid order to its microstore idempotently, updating store order count,
     * total revenue, conversion analytics event, and creator affiliate commission if applicable.
     */
    public function recordOrder(Order $order): void
    {
        if (MicrostoreEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'order_conversion')
            ->exists()) {
            return;
        }

        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            return;
        }

        $microstore = null;
        if ($order->microstore_id) {
            $microstore = Microstore::query()
                ->whereKey($order->microstore_id)
                ->where('status', 'active')
                ->where('moderation_status', 'approved')
                ->first();
        } else {
            $microstore = $this->resolveForOrder(
                (int) $order->user_id,
                [],
                $order->items->pluck('product_id')->all(),
            );
            if ($microstore) {
                $order->update(['microstore_id' => $microstore->id]);
            }
        }

        if (! $microstore || (int) $microstore->user_id === (int) $order->user_id) {
            return;
        }

        DB::transaction(function () use ($order, $microstore): void {
            if (MicrostoreEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'order_conversion')
                ->lockForUpdate()
                ->exists()) {
                return;
            }

            /** @var Microstore|null $lockedStore */
            $lockedStore = Microstore::query()
                ->whereKey($microstore->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedStore || ! $lockedStore->isPubliclyActive()) {
                return;
            }

            $curatedProductIds = MicrostoreProduct::query()
                ->where('microstore_id', $lockedStore->id)
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $matchingItems = $curatedProductIds === []
                ? collect()
                : $order->items->filter(
                    fn ($item): bool => in_array((int) $item->product_id, $curatedProductIds, true),
                );

            $attributedRevenue = $matchingItems->isNotEmpty()
                ? (float) $matchingItems->sum(fn ($item): float => (float) ($item->line_total ?? ((float) $item->price_at_purchase * (int) $item->quantity)))
                : (float) $order->subtotal;

            $attributedRevenue = round(max(0.0, $attributedRevenue), 2);
            if ($attributedRevenue <= 0.0) {
                return;
            }

            $lockedStore->update([
                'orders_count' => (int) $lockedStore->orders_count + 1,
                'total_revenue' => round((float) $lockedStore->total_revenue + $attributedRevenue, 2),
            ]);

            MicrostoreEvent::query()->create([
                'microstore_id' => $lockedStore->id,
                'user_id' => $order->user_id,
                'event_type' => 'order_conversion',
                'product_id' => $matchingItems->first()?->product_id ?? $order->items->first()?->product_id,
                'order_id' => $order->id,
                'metadata' => [
                    'order_number' => $order->number,
                    'attributed_revenue' => $attributedRevenue,
                    'currency' => $order->currency,
                ],
                'created_at' => now(),
            ]);

            $creatorAffiliate = Affiliate::query()
                ->where('user_id', $lockedStore->user_id)
                ->where('status', 'active')
                ->first();

            if ($creatorAffiliate) {
                $this->affiliates->recordSales($order, $creatorAffiliate->code);
            }
        });
    }

    /**
     * Reverse microstore order count and revenue when a paid order is cancelled or fully refunded.
     */
    public function reverseOrder(Order $order, string $reason): void
    {
        $conversionEvent = MicrostoreEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'order_conversion')
            ->first();

        if (! $conversionEvent) {
            return;
        }

        if (MicrostoreEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'order_reversal')
            ->exists()) {
            return;
        }

        DB::transaction(function () use ($order, $conversionEvent, $reason): void {
            if (MicrostoreEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'order_reversal')
                ->lockForUpdate()
                ->exists()) {
                return;
            }

            /** @var Microstore|null $lockedStore */
            $lockedStore = Microstore::query()
                ->whereKey($conversionEvent->microstore_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedStore) {
                return;
            }

            $attributedRevenue = round((float) data_get($conversionEvent->metadata, 'attributed_revenue', $order->subtotal), 2);

            $lockedStore->update([
                'orders_count' => max(0, (int) $lockedStore->orders_count - 1),
                'total_revenue' => max(0.0, round((float) $lockedStore->total_revenue - $attributedRevenue, 2)),
            ]);

            MicrostoreEvent::query()->create([
                'microstore_id' => $lockedStore->id,
                'user_id' => $order->user_id,
                'event_type' => 'order_reversal',
                'product_id' => $conversionEvent->product_id,
                'order_id' => $order->id,
                'metadata' => [
                    'order_number' => $order->number,
                    'reversed_revenue' => $attributedRevenue,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);
        });
    }
}

