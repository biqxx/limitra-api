<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Cart\Cart;

class ViewCartTool implements Tool
{
    public function getName(): string
    {
        return 'view_cart';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => "Retrieve the customer's active cart contents including product names, quantities, prices, and order total.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => "The customer's user ID"],
                ],
                'required' => ['user_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $cart = Cart::where('user_id', (int) $arguments['user_id'])
            ->where('status', 'active')
            ->with(['items.product'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return ['empty' => true, 'message' => 'Your cart is empty.'];
        }

        $items = $cart->items->map(fn ($item) => [
            'cart_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name,
            'price' => $item->product?->price,
            'quantity' => $item->quantity,
            'subtotal' => ($item->product?->price ?? 0) * $item->quantity,
        ]);

        return [
            'cart_id' => $cart->id,
            'items' => $items->toArray(),
            'item_count' => $items->count(),
            'total' => $items->sum('subtotal'),
        ];
    }
}
