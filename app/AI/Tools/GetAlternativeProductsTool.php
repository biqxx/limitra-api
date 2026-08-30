<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Product\Product;

class GetAlternativeProductsTool implements Tool
{
    public function getName(): string
    {
        return 'get_alternative_products';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Get alternative or similar products in the same category as the given product.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The product ID to find alternatives for'],
                ],
                'required' => ['product_id'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        $product = Product::find((int) $arguments['product_id']);

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        $alternatives = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('stock', '>', 0)
            ->limit(4)
            ->get(['id', 'name', 'price', 'stock']);

        return $alternatives->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => $p->price,
            'stock' => $p->stock,
        ])->toArray();
    }
}
