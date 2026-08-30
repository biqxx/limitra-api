<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
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
                'properties' => [],
                'required' => [],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        if ($context->userId === null) {
            return ['error' => 'Authentication is required to view a cart.'];
        }

        $cart = Cart::where('user_id', $context->userId)
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
