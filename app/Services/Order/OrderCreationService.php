<?php

namespace App\Services\Order;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\Promotion;
use App\Models\Commerce\PromotionRedemption;
use App\Models\IdempotencyKey;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Commerce\DeliveryService;
use App\Services\Commerce\PromotionService;
use App\Services\Payment\WalletCheckoutService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderCreationService
{
    private const OPERATION = 'create_order';

    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly PromotionService $promotions,
        private readonly BusinessSettingsService $settings,
        private readonly WalletCheckoutService $walletCheckout,
    ) {}

    /**
     * @return array{order: Order, replayed: bool}
     */
    public function create(array $data, int $userId, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($data);
        $existing = $this->findIdempotencyKey($userId, $idempotencyKey);

        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            return DB::transaction(function () use ($data, $userId, $idempotencyKey, $requestHash) {
                $existing = IdempotencyKey::where('user_id', $userId)
                    ->where('operation', self::OPERATION)
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $record = IdempotencyKey::create([
                    'user_id' => $userId,
                    'operation' => self::OPERATION,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                ]);

                $quote = CheckoutQuote::with('items')
                    ->where('quote_id', $data['quote_id'])
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertQuoteIsUsable($quote, $data['payment_method']);

                $cart = Cart::whereKey($quote->cart_id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($cart->status !== 'active') {
                    $this->invalidQuote('The cart has already been checked out.');
                }

                $cartItems = $cart->items()->with(['product', 'variant'])->lockForUpdate()->get();
                $cart->setRelation('items', $cartItems);
                $this->assertCartMatchesQuote($cart, $quote);

                $products = Product::whereIn('id', $quote->items->pluck('product_id')->unique()->sort()->values())
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $variantIds = $quote->items->pluck('variant_id')->filter()->unique()->sort()->values();
                $variants = $variantIds->isEmpty()
                    ? collect()
                    : ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                foreach ($cartItems as $cartItem) {
                    $cartItem->setRelation('product', $products->get($cartItem->product_id));
                    $cartItem->setRelation('variant', $cartItem->variant_id ? $variants->get($cartItem->variant_id) : null);
                }

                $this->assertInventoryAndPrices($quote, $products, $variants);
                $address = Address::whereKey($quote->address_id)->where('user_id', $userId)->firstOrFail();
                $this->assertTotalsAndRules($quote, $cart, $address, $userId);

                $isCash = $quote->payment_method === 'cash_on_delivery';
                $isWalletPaid = (float) $quote->grand_total === 0.0 && (float) $quote->wallet_credit > 0;
                $reservationExpiresAt = $isCash || $isWalletPaid
                    ? null
                    : now()->addMinutes((int) $this->settings->value('orders.inventory_reservation_minutes'));
                $order = Order::create([
                    'user_id' => $userId,
                    'checkout_quote_id' => $quote->id,
                    'number' => $this->orderNumber(),
                    'currency' => $quote->currency,
                    'subtotal' => $quote->subtotal,
                    'discount_total' => $quote->discount_total,
                    'credit_total' => $quote->wallet_credit,
                    'shipping_total' => $quote->shipping_total,
                    'grand_total' => $quote->grand_total,
                    'total_amount' => $quote->grand_total,
                    'status' => $isCash || $isWalletPaid ? 'confirmed' : 'pending_payment',
                    'payment_status' => $isWalletPaid ? 'paid' : ($isCash ? 'unpaid' : 'pending'),
                    'fulfilment_status' => 'unfulfilled',
                    'payment_method' => $quote->payment_method,
                    'contact_email' => strtolower($data['contact_email']),
                    'notes' => $data['notes'] ?? null,
                    'delivery_method' => $quote->shipping_snapshot['code'],
                    'estimated_delivery_at' => $this->estimatedDeliveryAt($quote),
                    'shipping_address_id' => $address->id,
                    'shipping_address' => $quote->address_snapshot,
                ]);
                $order->statusEvents()->create([
                    'from_status' => null,
                    'to_status' => $order->status,
                    'note' => 'Order created.',
                    'source' => 'system',
                ]);
                $this->walletCheckout->debitForOrder($order, $quote);

                foreach ($quote->items as $quoteItem) {
                    $order->items()->create([
                        'product_id' => $quoteItem->product_id,
                        'variant_id' => $quoteItem->variant_id,
                        'product_name' => $quoteItem->product_name,
                        'sku' => $quoteItem->sku,
                        'selected_options' => $quoteItem->selected_options,
                        'quantity' => $quoteItem->quantity,
                        'unit_price' => $quoteItem->unit_price,
                        'price_at_purchase' => $quoteItem->unit_price,
                        'line_total' => $quoteItem->line_total,
                    ]);

                    $stockModel = $quoteItem->variant_id
                        ? $variants->get($quoteItem->variant_id)
                        : $products->get($quoteItem->product_id);
                    $stockModel->decrement('stock', $quoteItem->quantity);

                    $order->reservations()->create([
                        'product_id' => $quoteItem->product_id,
                        'variant_id' => $quoteItem->variant_id,
                        'quantity' => $quoteItem->quantity,
                        'status' => 'reserved',
                        'expires_at' => $reservationExpiresAt,
                    ]);
                }

                if ($quote->promotion_id) {
                    PromotionRedemption::create([
                        'promotion_id' => $quote->promotion_id,
                        'user_id' => $userId,
                        'order_id' => $order->id,
                        'discount_amount' => $quote->discount_total,
                    ]);
                }

                $quote->update(['consumed_at' => now()]);
                $cart->checkout();
                $record->update([
                    'resource_type' => Order::class,
                    'resource_id' => $order->id,
                ]);

                return ['order' => $order, 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->findIdempotencyKey($userId, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }
    }

    private function assertQuoteIsUsable(CheckoutQuote $quote, string $paymentMethod): void
    {
        if ($quote->consumed_at) {
            $this->invalidQuote('This checkout quote has already been used.');
        }
        if ($quote->expires_at->isPast()) {
            $this->invalidQuote('This checkout quote has expired. Create a new quote.');
        }
        if ($quote->payment_method !== $paymentMethod) {
            $this->invalidQuote('The payment method must match the checkout quote.');
        }
    }

    private function assertCartMatchesQuote(Cart $cart, CheckoutQuote $quote): void
    {
        $cartLines = $cart->items->map(fn ($item) => $this->lineFingerprint(
            $item->product_id,
            $item->variant_id,
            $item->quantity,
            $item->selected_options,
        ))->sort()->values()->all();
        $quoteLines = $quote->items->map(fn ($item) => $this->lineFingerprint(
            $item->product_id,
            $item->variant_id,
            $item->quantity,
            $item->selected_options,
        ))->sort()->values()->all();

        if ($cartLines !== $quoteLines) {
            $this->invalidQuote('The cart changed after this quote was created. Create a new quote.');
        }
    }

    private function assertInventoryAndPrices(CheckoutQuote $quote, $products, $variants): void
    {
        $remainingStock = [];

        foreach ($quote->items as $item) {
            $product = $products->get($item->product_id);
            $variant = $item->variant_id ? $variants->get($item->variant_id) : null;
            if (! $product || $product->status !== 'active'
                || ($item->variant_id && (! $variant || $variant->product_id !== $product->id || $variant->status !== 'active'))) {
                $this->invalidQuote("{$item->product_name} is no longer available. Create a new quote.");
            }

            $stockModel = $variant ?? $product;
            $stockKey = $variant ? "variant:{$variant->id}" : "product:{$product->id}";
            $remainingStock[$stockKey] ??= $stockModel->stock;
            if ($remainingStock[$stockKey] < $item->quantity) {
                $this->invalidQuote("There is not enough stock for {$item->product_name}. Create a new quote.");
            }
            $remainingStock[$stockKey] -= $item->quantity;

            if (! $this->sameMoney($variant?->price ?? $product->price, $item->unit_price)) {
                $this->invalidQuote("The price of {$item->product_name} changed. Create a new quote.");
            }
        }
    }

    private function assertTotalsAndRules(CheckoutQuote $quote, Cart $cart, Address $address, int $userId): void
    {
        $subtotal = $cart->items->sum(fn ($item) => (float) ($item->variant?->price ?? $item->product->price) * $item->quantity);
        if (! $this->sameMoney($subtotal, $quote->subtotal)) {
            $this->invalidQuote('The cart total changed. Create a new quote.');
        }

        $promotion = null;
        if ($quote->promotion_id) {
            Promotion::whereKey($quote->promotion_id)->lockForUpdate()->firstOrFail();
            $promotion = $this->promotions->validate($quote->promotion_snapshot['code'], $cart, $userId);
            if (! $this->sameMoney($promotion['discount_amount'], $quote->discount_total)) {
                $this->invalidQuote('The promotion value changed. Create a new quote.');
            }
        }

        $shipping = collect($this->delivery->options($cart, $address->country, $address->state, $address->city))
            ->firstWhere('code', $quote->shipping_snapshot['code']);
        if (! $shipping) {
            $this->invalidQuote('The selected delivery method is no longer available. Create a new quote.');
        }

        $shippingTotal = ! empty($promotion['free_shipping']) ? 0 : (float) $shipping['fee'];
        $grandTotal = max(0, $subtotal - (float) $quote->discount_total - (float) $quote->wallet_credit + $shippingTotal);
        if (! $this->sameMoney($shippingTotal, $quote->shipping_total)
            || ! $this->sameMoney($grandTotal, $quote->grand_total)) {
            $this->invalidQuote('The checkout total changed. Create a new quote.');
        }
    }

    private function findIdempotencyKey(int $userId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::where('user_id', $userId)
            ->where('operation', self::OPERATION)
            ->where('key', $key)
            ->first();
    }

    /**
     * @return array{order: Order, replayed: bool}
     */
    private function replay(IdempotencyKey $record, string $requestHash): array
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used with different order details.');
        }
        if ($record->resource_type !== Order::class || ! $record->resource_id) {
            throw new ConflictHttpException('An order request with this idempotency key is still being processed.');
        }

        return [
            'order' => Order::whereKey($record->resource_id)->where('user_id', $record->user_id)->firstOrFail(),
            'replayed' => true,
        ];
    }

    private function requestHash(array $data): string
    {
        return hash('sha256', json_encode([
            'quote_id' => $data['quote_id'],
            'payment_method' => $data['payment_method'],
            'contact_email' => strtolower($data['contact_email']),
            'notes' => $data['notes'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function lineFingerprint(int $productId, ?int $variantId, int $quantity, ?array $options): string
    {
        $options ??= [];
        ksort($options);

        return json_encode([$productId, $variantId, $quantity, $options], JSON_THROW_ON_ERROR);
    }

    private function sameMoney(mixed $left, mixed $right): bool
    {
        return number_format((float) $left, 2, '.', '') === number_format((float) $right, 2, '.', '');
    }

    private function orderNumber(): string
    {
        return 'LMT-'.now()->format('ymd').'-'.Str::upper(Str::random(10));
    }

    private function estimatedDeliveryAt(CheckoutQuote $quote)
    {
        $days = $quote->shipping_snapshot['estimated_days']['max'] ?? null;

        return $days === null ? null : now()->addDays((int) $days)->endOfDay();
    }

    private function invalidQuote(string $message): never
    {
        throw ValidationException::withMessages(['quote_id' => [$message]]);
    }
}
