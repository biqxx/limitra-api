<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Cart\Cart;
use App\Models\Product\Product;
use App\Services\Cart\CartService;
use Illuminate\Validation\ValidationException;

class AddToCartTool implements Tool
{
    public function __construct(private readonly CartService $carts) {}

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
                    'variant_id' => ['type' => 'integer', 'description' => 'The selected product variant ID'],
                    'selected_options' => ['type' => 'object', 'description' => 'Selected product options'],
                    'quantity' => ['type' => 'integer', 'description' => 'Quantity to add (default 1)'],
                ],
                'required' => ['product_id'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        if ($context->userId === null) {
            return ['error' => 'Authentication is required to modify a cart.'];
        }

        $product = Product::find((int) $arguments['product_id']);

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        $quantity = max(1, (int) ($arguments['quantity'] ?? 1));

        $cart = Cart::activeForUser($context->userId);

        try {
            $cart = $this->carts->add(
                $cart,
                $product,
                isset($arguments['variant_id']) ? (int) $arguments['variant_id'] : null,
                (array) ($arguments['selected_options'] ?? []),
                $quantity,
            );
        } catch (ValidationException $exception) {
            return ['error' => collect($exception->errors())->flatten()->first()];
        }

        return [
            'success' => true,
            'cart_id' => $cart->id,
            'product' => $product->name,
            'quantity' => $quantity,
        ];
    }
}
