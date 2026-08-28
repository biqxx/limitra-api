<?php

namespace App\Services\Cart;

use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    public const RELATIONS = [
        'items.product.category',
        'items.product.productImages',
        'items.variant',
    ];

    public function add(Cart $cart, Product $product, ?int $variantId, array $selectedOptions, int $quantity): Cart
    {
        return DB::transaction(function () use ($cart, $product, $variantId, $selectedOptions, $quantity): Cart {
            [$variant, $options, $price, $stock] = $this->selection($product, $variantId, $selectedOptions);
            $this->ensureCartAccepts($cart, $product);
            $lineKey = $this->lineKey($product->id, $variant?->id, $options);
            $item = $cart->items()->where('line_key', $lineKey)->lockForUpdate()->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;
            $this->ensureStock($stock, $newQuantity);

            if ($item) {
                $item->update(['quantity' => $newQuantity]);
            } else {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'selected_options' => $options ?: null,
                    'line_key' => $lineKey,
                    'quantity' => $quantity,
                    'unit_price_at_addition' => $price,
                ]);
            }

            return $this->fresh($cart);
        });
    }

    public function update(
        CartItem $item,
        int $quantity,
        ?int $variantId = null,
        ?array $selectedOptions = null,
        bool $replaceSelection = false
    ): Cart {
        return DB::transaction(function () use ($item, $quantity, $variantId, $selectedOptions, $replaceSelection): Cart {
            $cart = $item->cart()->lockForUpdate()->firstOrFail();
            $targetVariantId = $replaceSelection ? $variantId : $item->variant_id;
            $targetOptions = $replaceSelection ? ($selectedOptions ?? []) : ($item->selected_options ?? []);
            [$variant, $options, $price, $stock] = $this->selection($item->product, $targetVariantId, $targetOptions);
            $this->ensureStock($stock, $quantity);
            $lineKey = $this->lineKey($item->product_id, $variant?->id, $options);
            $duplicate = $cart->items()->where('line_key', $lineKey)->whereKeyNot($item->id)->lockForUpdate()->first();

            if ($duplicate) {
                $combinedQuantity = $duplicate->quantity + $quantity;
                $this->ensureStock($stock, $combinedQuantity);
                $duplicate->update(['quantity' => $combinedQuantity]);
                $item->delete();
            } else {
                $item->update([
                    'variant_id' => $variant?->id,
                    'selected_options' => $options ?: null,
                    'line_key' => $lineKey,
                    'quantity' => $quantity,
                    'unit_price_at_addition' => $price,
                ]);
            }

            return $this->fresh($cart);
        });
    }

    public function fresh(Cart $cart): Cart
    {
        return $cart->fresh(self::RELATIONS);
    }

    public function lineKey(int $productId, ?int $variantId, array $selectedOptions): string
    {
        $options = $this->canonicalize($selectedOptions);

        return hash('sha256', json_encode([$productId, $variantId, $options], JSON_THROW_ON_ERROR));
    }

    private function selection(Product $product, ?int $variantId, array $selectedOptions): array
    {
        if ($product->status !== 'active') {
            throw ValidationException::withMessages(['product_id' => ['The selected product is unavailable.']]);
        }

        $variant = null;
        if ($variantId !== null) {
            $variant = ProductVariant::whereKey($variantId)->where('product_id', $product->id)->first();
            if (! $variant || $variant->status !== 'active') {
                throw ValidationException::withMessages(['variant_id' => ['The selected variant is unavailable for this product.']]);
            }

            $variantOptions = $this->canonicalize($variant->attributes ?? []);
            if ($selectedOptions && $this->canonicalize($selectedOptions) !== $variantOptions) {
                throw ValidationException::withMessages(['selected_options' => ['The selected options do not match the variant.']]);
            }
            $selectedOptions = $variantOptions;
        } elseif ($product->variants()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['variant_id' => ['A variant is required for this product.']]);
        }

        return [
            $variant,
            $this->canonicalize($selectedOptions),
            $variant?->price ?? $product->price,
            $variant?->stock ?? $product->stock,
        ];
    }

    private function ensureCartAccepts(Cart $cart, Product $product): void
    {
        if ($cart->status !== 'active') {
            throw ValidationException::withMessages(['cart' => ['Items can only be added to an active cart.']]);
        }

        if ($cart->items()->exists() && $cart->currency !== $product->currency) {
            throw ValidationException::withMessages(['product_id' => ['All cart items must use the same currency.']]);
        }

        if (! $cart->items()->exists() && $cart->currency !== $product->currency) {
            $cart->update(['currency' => $product->currency]);
        }
    }

    private function ensureStock(int $stock, int $quantity): void
    {
        if ($quantity > $stock) {
            throw ValidationException::withMessages(['quantity' => ["Only {$stock} item(s) are available."]]);
        }
    }

    private function canonicalize(array $options): array
    {
        $options = Arr::undot(Arr::dot($options));
        ksort($options);

        foreach ($options as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalize($value);
            }
        }

        return $options;
    }
}
