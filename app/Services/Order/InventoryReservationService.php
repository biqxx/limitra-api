<?php

namespace App\Services\Order;

use App\Models\Commerce\PromotionRedemption;
use App\Models\Order\InventoryReservation;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReservationService
{
    public const EXPIRY_CANCELLATION_CODE = 'inventory_reservation_expired';

    public const LATE_PAYMENT_CANCELLATION_CODE = 'late_payment_inventory_unavailable';

    public function releaseExpired(): int
    {
        $orderIds = InventoryReservation::query()
            ->where('status', 'reserved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereHas('order', fn ($query) => $query
                ->where('status', 'pending_payment')
                ->where('payment_status', '!=', 'paid'))
            ->orderBy('id')
            ->limit(max(1, (int) config('maintenance.prune_batch_size', 1000)))
            ->pluck('order_id')
            ->unique();

        return $orderIds->sum(fn ($orderId): int => $this->releaseOrder((int) $orderId) ? 1 : 0);
    }

    public function releaseOrder(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order || $order->status !== 'pending_payment' || $order->payment_status === 'paid') {
                return false;
            }

            $reservations = $order->reservations()
                ->where('status', 'reserved')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty() || ! $reservations->contains(
                fn (InventoryReservation $reservation): bool => $reservation->expires_at?->isPast() ?? false,
            )) {
                return false;
            }

            [$products, $variants] = $this->lockStock($reservations);

            foreach ($reservations as $reservation) {
                $stock = $reservation->variant_id
                    ? $variants->get($reservation->variant_id)
                    : $products->get($reservation->product_id);

                $stock?->increment('stock', $reservation->quantity);
                $reservation->update([
                    'status' => 'released',
                    'released_at' => now(),
                ]);
            }

            PromotionRedemption::query()->where('order_id', $order->id)->delete();
            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'failed',
                'fulfilment_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_code' => self::EXPIRY_CANCELLATION_CODE,
                'cancellation_reason' => 'Payment was not completed before the inventory reservation expired.',
            ]);
            $order->statusEvents()->create([
                'from_status' => 'pending_payment',
                'to_status' => 'cancelled',
                'note' => 'Inventory reservation expired before payment was completed.',
                'source' => 'system',
            ]);

            return true;
        }, 3);
    }

    public function restoreForLatePayment(Order $order): bool
    {
        if ($order->status !== 'cancelled' || $order->cancellation_code !== self::EXPIRY_CANCELLATION_CODE) {
            return false;
        }

        $reservations = $order->reservations()
            ->where('status', 'released')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return false;
        }

        [$products, $variants] = $this->lockStock($reservations);
        $required = [];

        foreach ($reservations as $reservation) {
            $key = $reservation->variant_id
                ? 'variant:'.$reservation->variant_id
                : 'product:'.$reservation->product_id;
            $required[$key] = ($required[$key] ?? 0) + $reservation->quantity;
        }

        foreach ($required as $key => $quantity) {
            [$type, $id] = explode(':', $key);
            $stock = $type === 'variant' ? $variants->get((int) $id) : $products->get((int) $id);

            if (! $stock || $stock->stock < $quantity) {
                return false;
            }
        }

        foreach ($reservations as $reservation) {
            $stock = $reservation->variant_id
                ? $variants->get($reservation->variant_id)
                : $products->get($reservation->product_id);
            $stock->decrement('stock', $reservation->quantity);
            $reservation->update([
                'status' => 'reserved',
                'expires_at' => null,
                'released_at' => null,
            ]);
        }

        $quote = $order->checkoutQuote;
        if ($quote?->promotion_id && ! PromotionRedemption::query()->where('order_id', $order->id)->exists()) {
            PromotionRedemption::query()->create([
                'promotion_id' => $quote->promotion_id,
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'discount_amount' => $order->discount_total,
            ]);
        }

        return true;
    }

    public function removeExpiry(Order $order): void
    {
        $order->reservations()
            ->where('status', 'reserved')
            ->whereNotNull('expires_at')
            ->update(['expires_at' => null]);
    }

    /**
     * @param  Collection<int, InventoryReservation>  $reservations
     * @return array{Collection<int, Product>, Collection<int, ProductVariant>}
     */
    private function lockStock(Collection $reservations): array
    {
        $products = Product::query()
            ->withTrashed()
            ->whereIn('id', $reservations->pluck('product_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variantIds = $reservations->pluck('variant_id')->filter()->unique()->sort()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        return [$products, $variants];
    }
}
