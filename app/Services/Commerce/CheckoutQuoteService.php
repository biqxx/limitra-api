<?php

namespace App\Services\Commerce;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Payment\SavedCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutQuoteService
{
    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly PromotionService $promotions
    ) {}

    public function create(array $data, int $userId): CheckoutQuote
    {
        if (! empty($data['use_wallet_credit'])) {
            throw ValidationException::withMessages(['use_wallet_credit' => ['Wallet credit is not available until the immutable wallet ledger is enabled.']]);
        }

        $cart = Cart::whereKey($data['cart_id'])->where('user_id', $userId)->where('status', 'active')->firstOrFail();
        $address = Address::whereKey($data['address_id'])->where('user_id', $userId)->firstOrFail();
        $cart->load(['items.product', 'items.variant']);
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart_id' => ['The cart is empty.']]);
        }

        $warnings = [];
        $lines = $cart->items->map(function ($item) use (&$warnings) {
            $product = $item->product;
            $variant = $item->variant;
            $stock = $variant?->stock ?? $product->stock;
            $price = $variant?->price ?? $product->price;
            if ($product->status !== 'active' || ($item->variant_id && (! $variant || $variant->status !== 'active'))) {
                throw ValidationException::withMessages(['cart_id' => ["{$product->name} is no longer available."]]);
            }
            if ($stock < $item->quantity) {
                throw ValidationException::withMessages(['cart_id' => ["Only {$stock} of {$product->name} are available."]]);
            }
            if (number_format((float) $price, 2, '.', '') !== number_format((float) $item->unit_price_at_addition, 2, '.', '')) {
                $warnings[] = ['type' => 'price_changed', 'cart_item_id' => $item->id, 'message' => "The price of {$product->name} changed."];
            }

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'product_name' => $product->name,
                'sku' => $variant?->sku ?? $product->sku,
                'selected_options' => $item->selected_options,
                'quantity' => $item->quantity,
                'unit_price' => $price,
                'line_total' => (float) $price * $item->quantity,
            ];
        });
        $subtotal = $lines->sum('line_total');

        $deliveryOptions = $this->delivery->options($cart, $address->country, $address->state, $address->city);
        $shipping = collect($deliveryOptions)->firstWhere('code', $data['delivery_method']);
        if (! $shipping) {
            throw ValidationException::withMessages(['delivery_method' => ['The selected delivery method is unavailable for this cart and address.']]);
        }
        $deliveryMethod = DeliveryMethod::where('code', $data['delivery_method'])->where('active', true)->firstOrFail();

        $savedCard = null;
        if (isset($data['saved_card_id'])) {
            $savedCard = SavedCard::whereKey($data['saved_card_id'])->where('user_id', $userId)->firstOrFail();
            if ($data['payment_method'] !== 'card' || ! $savedCard->reusable || $savedCard->isExpired()) {
                throw ValidationException::withMessages(['saved_card_id' => ['The selected saved payment method cannot be used.']]);
            }
        }
        if ($data['payment_method'] === 'card' && isset($data['saved_card_id']) && ! $savedCard) {
            throw ValidationException::withMessages(['saved_card_id' => ['The selected saved payment method cannot be used.']]);
        }

        $promotion = null;
        if (! empty($data['promotion_code'])) {
            $promotion = $this->promotions->validate($data['promotion_code'], $cart, $userId);
        }
        $discount = (float) ($promotion['discount_amount'] ?? 0);
        $shippingTotal = ! empty($promotion['free_shipping']) ? 0 : (float) $shipping['fee'];
        $shipping['fee'] = number_format($shippingTotal, 2, '.', '');
        $grandTotal = max(0, $subtotal - $discount + $shippingTotal);

        return DB::transaction(function () use ($data, $userId, $cart, $address, $deliveryMethod, $savedCard, $promotion, $subtotal, $discount, $shippingTotal, $grandTotal, $shipping, $warnings, $lines) {
            $quote = CheckoutQuote::create([
                'user_id' => $userId,
                'cart_id' => $cart->id,
                'address_id' => $address->id,
                'delivery_method_id' => $deliveryMethod->id,
                'saved_card_id' => $savedCard?->id,
                'promotion_id' => $promotion['id'] ?? null,
                'payment_method' => $data['payment_method'],
                'currency' => $cart->currency,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'shipping_total' => $shippingTotal,
                'wallet_credit' => 0,
                'grand_total' => $grandTotal,
                'address_snapshot' => [
                    'label' => $address->label, 'recipient_name' => $address->recipient_name, 'phone' => $address->phone,
                    'line1' => $address->line1, 'line2' => $address->line2, 'city' => $address->city,
                    'state' => $address->state, 'postal_code' => $address->postal_code, 'country' => $address->country,
                ],
                'shipping_snapshot' => $shipping,
                'promotion_snapshot' => $promotion,
                'warnings' => $warnings,
                'expires_at' => now()->addMinutes(15),
            ]);
            $quote->items()->createMany($lines->all());

            return $quote->load('items');
        });
    }
}
