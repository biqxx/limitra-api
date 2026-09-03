<?php

namespace App\Services\Order;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\PromotionRedemption;
use App\Models\Order\Order;
use App\Models\Order\Shipment;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Cart\CartService;
use App\Services\Commerce\DeliveryService;
use App\Services\Referral\ReferralRewardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderLifecycleService
{
    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly CartService $carts,
        private readonly ReferralRewardService $referralRewards,
    ) {}

    public function cancel(Order $order, int $userId, string $reason): Order
    {
        return DB::transaction(function () use ($order, $userId, $reason) {
            $order = Order::whereKey($order->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($order->status === 'cancelled') {
                return $order;
            }
            if (! in_array($order->status, ['pending_payment', 'confirmed'], true)) {
                $this->invalid('order', 'This order can no longer be cancelled.');
            }

            $payments = $order->payments()->lockForUpdate()->get();
            if ($order->payment_status === 'paid' || $payments->contains(fn ($payment) => in_array($payment->status, ['initializing', 'pending', 'succeeded'], true))) {
                $this->invalid('order', 'This order has an active or completed payment and requires a refund workflow.');
            }

            $reservations = $order->reservations()->where('status', 'reserved')->orderBy('id')->lockForUpdate()->get();
            $products = Product::whereIn('id', $reservations->pluck('product_id')->unique()->sort()->values())
                ->withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variantIds = $reservations->pluck('variant_id')->filter()->unique()->sort()->values();
            $variants = $variantIds->isEmpty()
                ? collect()
                : ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($reservations as $reservation) {
                $stockModel = $reservation->variant_id
                    ? $variants->get($reservation->variant_id)
                    : $products->get($reservation->product_id);
                $stockModel?->increment('stock', $reservation->quantity);
                $reservation->update(['status' => 'released', 'released_at' => now()]);
            }

            PromotionRedemption::where('order_id', $order->id)->delete();
            $fromStatus = $order->status;
            $order->update([
                'status' => 'cancelled',
                'fulfilment_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancellation_code' => 'customer_requested',
            ]);
            $order->statusEvents()->create([
                'from_status' => $fromStatus,
                'to_status' => 'cancelled',
                'note' => $reason,
                'source' => 'customer',
                'actor_id' => $userId,
            ]);

            return $order->fresh();
        }, 3);
    }

    public function updateDeliveryAddress(Order $order, Address $address, int $userId): Order
    {
        return DB::transaction(function () use ($order, $address, $userId) {
            $order = Order::whereKey($order->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $address = Address::whereKey($address->id)->where('user_id', $userId)->firstOrFail();
            if (! in_array($order->status, ['pending_payment', 'confirmed'], true) || $order->shipment()->exists()) {
                $this->invalid('address_id', 'The delivery address can no longer be changed.');
            }

            $option = collect($this->delivery->optionsForSubtotal(
                (float) $order->subtotal,
                $order->currency,
                $address->country,
                $address->state,
                $address->city,
            ))->firstWhere('code', $order->delivery_method);
            if (! $option) {
                $this->invalid('address_id', 'The selected delivery method is unavailable for this address.');
            }

            $freeShipping = (bool) data_get($order->checkoutQuote?->promotion_snapshot, 'free_shipping', false);
            $expectedFee = $freeShipping ? 0 : (float) $option['fee'];
            if (! $this->sameMoney($expectedFee, $order->shipping_total)) {
                $this->invalid('address_id', 'Changing to this address would change the delivery fee. Create a new order or contact support.');
            }

            $order->update([
                'shipping_address_id' => $address->id,
                'shipping_address' => $this->addressSnapshot($address),
                'estimated_delivery_at' => now()->addDays((int) $option['estimated_days']['max'])->endOfDay(),
            ]);

            return $order->fresh();
        }, 3);
    }

    public function ship(Order $order, array $data, int $actorId): Order
    {
        return DB::transaction(function () use ($order, $data, $actorId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['confirmed', 'processing'], true)) {
                $this->invalid('order', 'Only confirmed or processing orders can be shipped.');
            }
            if ($order->payment_method !== 'cash_on_delivery' && $order->payment_status !== 'paid') {
                $this->invalid('order', 'Online-payment orders must be paid before shipping.');
            }
            if ($order->shipment()->exists()) {
                $this->invalid('tracking_number', 'This order already has a shipment.');
            }

            $shipment = $order->shipment()->create([
                'courier' => $data['courier'],
                'tracking_number' => $data['tracking_number'],
                'status' => 'shipped',
                'estimated_delivery_at' => $data['estimated_delivery_at'] ?? $order->estimated_delivery_at,
                'shipped_at' => now(),
            ]);
            $shipment->events()->create([
                'status' => 'shipped',
                'description' => $data['note'] ?? 'Order handed to courier.',
                'location' => $data['location'] ?? null,
                'occurred_at' => now(),
                'source' => 'admin',
                'created_by' => $actorId,
            ]);
            $order->reservations()->where('status', 'reserved')->update([
                'status' => 'committed',
                'committed_at' => now(),
            ]);
            $this->transitionOrder($order, 'shipped', $data['note'] ?? null, $actorId);

            return $order->fresh();
        }, 3);
    }

    public function transition(Order $order, string $status, array $data, int $actorId): Order
    {
        return DB::transaction(function () use ($order, $status, $data, $actorId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $allowed = [
                'confirmed' => ['processing'],
                'shipped' => ['in_transit'],
                'in_transit' => ['delivered'],
            ];
            if (! in_array($status, $allowed[$order->status] ?? [], true)) {
                $this->invalid('status', "Order cannot move from {$order->status} to {$status}.");
            }

            if (in_array($status, ['in_transit', 'delivered'], true)) {
                $shipment = Shipment::where('order_id', $order->id)->lockForUpdate()->first();
                if (! $shipment) {
                    $this->invalid('status', 'A shipment is required for this transition.');
                }
                $shipment->update([
                    'status' => $status,
                    'delivered_at' => $status === 'delivered' ? ($data['occurred_at'] ?? now()) : null,
                ]);
                $shipment->events()->create([
                    'status' => $status,
                    'description' => $data['note'],
                    'location' => $data['location'] ?? null,
                    'occurred_at' => $data['occurred_at'] ?? now(),
                    'source' => 'admin',
                    'created_by' => $actorId,
                ]);
            }

            $this->transitionOrder($order, $status, $data['note'], $actorId);
            if ($status === 'delivered') {
                $this->referralRewards->grantForDeliveredOrder($order);
            }

            return $order->fresh();
        }, 3);
    }

    public function buyAgain(Order $order, int $userId, ?array $itemIds = null): Cart
    {
        return DB::transaction(function () use ($order, $userId, $itemIds) {
            $order = Order::whereKey($order->id)->where('user_id', $userId)->firstOrFail();
            $items = $order->items()
                ->with(['product' => fn ($query) => $query->withTrashed(), 'variant'])
                ->when($itemIds, fn ($query) => $query->whereIn('id', $itemIds))
                ->get();
            if ($items->isEmpty() || ($itemIds && $items->count() !== count(array_unique($itemIds)))) {
                $this->invalid('item_ids', 'One or more selected order items are invalid.');
            }

            $cart = Cart::activeForUser($userId);
            foreach ($items as $item) {
                if (! $item->product || $item->product->trashed()) {
                    $this->invalid('item_ids', "{$item->product_name} is no longer available.");
                }
                $this->carts->add(
                    $cart,
                    $item->product,
                    $item->variant_id,
                    $item->selected_options ?? [],
                    $item->quantity,
                );
            }

            return $this->carts->fresh($cart);
        }, 3);
    }

    private function transitionOrder(Order $order, string $status, ?string $note, int $actorId): void
    {
        $fromStatus = $order->status;
        $order->update(['status' => $status, 'fulfilment_status' => $status]);
        $order->statusEvents()->create([
            'from_status' => $fromStatus,
            'to_status' => $status,
            'note' => $note,
            'source' => 'admin',
            'actor_id' => $actorId,
        ]);
    }

    private function addressSnapshot(Address $address): array
    {
        return [
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
        ];
    }

    private function sameMoney(mixed $left, mixed $right): bool
    {
        return number_format((float) $left, 2, '.', '') === number_format((float) $right, 2, '.', '');
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
