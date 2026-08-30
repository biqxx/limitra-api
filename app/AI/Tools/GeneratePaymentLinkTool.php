<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Cart\Cart;

class GeneratePaymentLinkTool implements Tool
{
    public function getName(): string
    {
        return 'generate_payment_link';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => "Generate a checkout payment link for a customer's cart.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'cart_id' => ['type' => 'integer', 'description' => 'The cart ID'],
                ],
                'required' => ['cart_id'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        if ($context->userId === null) {
            return ['error' => 'Authentication is required to check out.'];
        }

        $cart = Cart::with('items.product')
            ->where('user_id', $context->userId)
            ->where('status', 'active')
            ->find((int) $arguments['cart_id']);

        if (! $cart || $cart->items->isEmpty()) {
            return ['error' => 'Cart not found or is empty'];
        }

        $total = $cart->items->sum(fn ($item) => $item->quantity * $item->product->price);

        // Generates a signed frontend checkout URL. Replace with your actual payment gateway logic.
        $checkoutUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/checkout?cart='.$cart->id
            .'&token='.hash_hmac('sha256', (string) $cart->id, config('app.key'));

        return [
            'cart_id' => $cart->id,
            'total' => number_format((float) $total, 2),
            'item_count' => $cart->items->count(),
            'checkout_url' => $checkoutUrl,
        ];
    }
}
