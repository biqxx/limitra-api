<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Product\Product;

class GetProductDetailsTool implements Tool
{
    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Get full details of a specific product by its ID, including description, price, stock, and category.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The product ID'],
                ],
                'required' => ['product_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $product = Product::with('category', 'subcategory')
            ->find((int) $arguments['product_id']);

        if (! $product) {
            return ['error' => 'Product not found'];
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'stock' => $product->stock,
            'category' => $product->category?->name,
            'subcategory' => $product->subcategory?->name,
        ];
    }
}
