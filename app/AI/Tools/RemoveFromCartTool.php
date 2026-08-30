<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Product\Product;

class RemoveFromCartTool implements Tool
{
    public function getName(): string
    {
        return 'remove_from_cart';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => "Remove a product from the customer's active cart.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The product ID to remove'],
                    'user_id' => ['type' => 'integer', 'description' => "The customer's user ID"],
                ],
                'required' => ['product_id', 'user_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $cart = Cart::where('user_id', (int) $arguments['user_id'])
            ->where('status', 'active')
            ->first();

        if (! $cart) {
            return ['error' => 'No active cart found.'];
        }

        $item = CartItem::where('cart_id', $cart->id)
            ->where('product_id', (int) $arguments['product_id'])
            ->first();

        if (! $item) {
            return ['error' => 'Product not found in cart.'];
        }

        $name = Product::find($arguments['product_id'])?->name ?? 'Product';
        $item->delete();

        return [
            'success' => true,
            'message' => "{$name} has been removed from your cart.",
        ];
    }
}
