<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Product\Product;

class AddToCartTool implements Tool
{
    public function getName(): string
    {
        return 'add_to_cart';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Add a product to the customer\'s cart.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The product ID to add'],
                    'quantity' => ['type' => 'integer', 'description' => 'Quantity to add (default 1)'],
                    'user_id' => ['type' => 'integer', 'description' => 'The customer\'s user ID'],
                ],
                'required' => ['product_id', 'user_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $product = Product::find((int) $arguments['product_id']);

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        $quantity = max(1, (int) ($arguments['quantity'] ?? 1));

        if ($product->stock < $quantity) {
            return ['error' => "Only {$product->stock} units available"];
        }

        $cart = Cart::firstOrCreate(['user_id' => (int) $arguments['user_id']]);

        $item = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $item->increment('quantity', $quantity);
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return [
            'success' => true,
            'cart_id' => $cart->id,
            'product' => $product->name,
            'quantity' => $quantity,
        ];
    }
}
