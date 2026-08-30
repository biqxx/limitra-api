<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Product\Product;

class SearchProductsTool implements Tool
{
    public function getName(): string
    {
        return 'search_products';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Search for products by name or keyword. Returns a list of matching products with their ID, name, price, and stock.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search keyword or product name'],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $products = Product::where('name', 'like', "%{$arguments['query']}%")
            ->where('stock', '>', 0)
            ->with('category')
            ->limit(5)
            ->get(['id', 'name', 'price', 'stock', 'category_id']);

        return $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => $p->price,
            'stock' => $p->stock,
            'category' => $p->category?->name,
        ])->toArray();
    }
}
