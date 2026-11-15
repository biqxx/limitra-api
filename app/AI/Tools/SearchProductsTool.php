<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Builder;

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
            'description' => 'Search active store products using keywords, category, brand, price or price range, specifications (e.g. RAM, battery, material), or variants (e.g. color, size, storage).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Keyword(s) matching product name, brand, description, category, specifications, or variants'],
                    'category' => ['type' => 'string', 'description' => 'Category or subcategory name or slug (e.g. "smartphones", "fashion")'],
                    'brand' => ['type' => 'string', 'description' => 'Brand name (e.g. "Apple", "Samsung", "Nike")'],
                    'min_price' => ['type' => 'number', 'description' => 'Minimum price in NGN'],
                    'max_price' => ['type' => 'number', 'description' => 'Maximum price in NGN'],
                    'specification' => ['type' => 'string', 'description' => 'Specification name or value (e.g. "256GB", "OLED", "cotton", "5000mAh")'],
                    'variant' => ['type' => 'string', 'description' => 'Variant attribute or SKU (e.g. color like "black", size like "XL" or "42", storage like "128GB")'],
                    'in_stock_only' => ['type' => 'boolean', 'description' => 'Only include products/variants with available stock (default true)'],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['relevance', 'price_asc', 'price_desc', 'rating', 'newest'],
                        'description' => 'Sort order for results',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of results to return (1-15, default 8)'],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        $inStockOnly = array_key_exists('in_stock_only', $arguments)
            ? (bool) $arguments['in_stock_only']
            : true;

        $limit = min(max((int) ($arguments['limit'] ?? 8), 1), 15);
        $sort = (string) ($arguments['sort'] ?? 'relevance');

        $query = Product::query()
            ->where('status', 'active')
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'specifications:id,product_id,group,name,value',
                'variants' => fn ($q) => $q->where('status', 'active')->select('id', 'product_id', 'sku', 'attributes', 'price', 'stock', 'status'),
            ]);
        $driver = $query->getConnection()->getDriverName();
        $castType = in_array($driver, ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';

        if ($inStockOnly) {
            $query->where(function (Builder $q): void {
                $q->where('stock', '>', 0)
                    ->orWhereHas('variants', fn (Builder $vq) => $vq->where('status', 'active')->where('stock', '>', 0));
            });
        }

        if (! empty($arguments['category'])) {
            $cat = trim((string) $arguments['category']);
            $query->where(function (Builder $q) use ($cat): void {
                $q->whereHas('category', fn (Builder $cq) => $cq->where('name', 'like', "%{$cat}%")->orWhere('slug', 'like', "%{$cat}%"))
                    ->orWhereHas('subcategory', fn (Builder $sq) => $sq->where('name', 'like', "%{$cat}%")->orWhere('slug', 'like', "%{$cat}%"));
            });
        }

        if (! empty($arguments['brand'])) {
            $brand = trim((string) $arguments['brand']);
            $query->where('brand', 'like', "%{$brand}%");
        }

        if (isset($arguments['min_price']) && is_numeric($arguments['min_price'])) {
            $minPrice = (float) $arguments['min_price'];
            $query->where(function (Builder $q) use ($minPrice): void {
                $q->where('price', '>=', $minPrice)
                    ->orWhereHas('variants', fn (Builder $vq) => $vq->where('status', 'active')->whereNotNull('price')->where('price', '>=', $minPrice));
            });
        }

        if (isset($arguments['max_price']) && is_numeric($arguments['max_price'])) {
            $maxPrice = (float) $arguments['max_price'];
            $query->where(function (Builder $q) use ($maxPrice): void {
                $q->where('price', '<=', $maxPrice)
                    ->orWhereHas('variants', fn (Builder $vq) => $vq->where('status', 'active')->whereNotNull('price')->where('price', '<=', $maxPrice));
            });
        }

        if (! empty($arguments['specification'])) {
            $spec = trim((string) $arguments['specification']);
            $query->whereHas('specifications', function (Builder $sq) use ($spec): void {
                $sq->where('name', 'like', "%{$spec}%")
                    ->orWhere('value', 'like', "%{$spec}%")
                    ->orWhere('group', 'like', "%{$spec}%");
            });
        }

        if (! empty($arguments['variant'])) {
            $variant = trim((string) $arguments['variant']);
            $query->whereHas('variants', function (Builder $vq) use ($variant, $inStockOnly, $castType): void {
                $vq->where('status', 'active')
                    ->when($inStockOnly, fn (Builder $sq) => $sq->where('stock', '>', 0))
                    ->where(function (Builder $mq) use ($variant, $castType): void {
                        $mq->where('sku', 'like', "%{$variant}%")
                            ->orWhereRaw("LOWER(CAST(attributes AS {$castType})) LIKE ?", ['%'.mb_strtolower($variant).'%']);
                    });
            });
        }

        if (! empty($arguments['query'])) {
            $rawQuery = trim((string) $arguments['query']);
            $tokens = array_values(array_filter(preg_split('/\s+/', $rawQuery) ?: [], fn (string $t): bool => mb_strlen($t) >= 2));

            if (empty($tokens)) {
                $tokens = [$rawQuery];
            }

            foreach ($tokens as $token) {
                $lowerToken = mb_strtolower($token);
                $query->where(function (Builder $q) use ($token, $lowerToken, $castType): void {
                    $q->where('name', 'like', "%{$token}%")
                        ->orWhere('brand', 'like', "%{$token}%")
                        ->orWhere('description', 'like', "%{$token}%")
                        ->orWhereHas('category', fn (Builder $cq) => $cq->where('name', 'like', "%{$token}%"))
                        ->orWhereHas('subcategory', fn (Builder $sq) => $sq->where('name', 'like', "%{$token}%"))
                        ->orWhereHas('specifications', fn (Builder $spq) => $spq->where('name', 'like', "%{$token}%")->orWhere('value', 'like', "%{$token}%"))
                        ->orWhereHas('variants', fn (Builder $vq) => $vq->where('status', 'active')->where(function (Builder $vm) use ($token, $lowerToken, $castType): void {
                            $vm->where('sku', 'like', "%{$token}%")
                                ->orWhereRaw("LOWER(CAST(attributes AS {$castType})) LIKE ?", ["%{$lowerToken}%"]);
                        }));
                });
            }
        }

        match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'rating' => $query->orderByDesc('average_rating')->orderByDesc('review_count'),
            'newest' => $query->latest('id'),
            default => $query->orderByDesc('is_featured')->orderByDesc('is_bestseller')->orderByDesc('average_rating')->latest('id'),
        };

        $products = $query->limit($limit)->get([
            'id', 'name', 'slug', 'brand', 'price', 'compare_at_price',
            'stock', 'average_rating', 'category_id', 'subcategory_id',
        ]);

        return $products->map(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'brand' => $p->brand,
            'price' => (float) $p->price,
            'compare_at_price' => $p->compare_at_price ? (float) $p->compare_at_price : null,
            'stock' => (int) $p->stock,
            'average_rating' => (float) $p->average_rating,
            'category' => $p->category?->name,
            'subcategory' => $p->subcategory?->name,
            'specifications' => $p->specifications->take(6)->map(fn ($s): array => [
                'name' => $s->name,
                'value' => $s->value,
            ])->values()->all(),
            'variants' => $p->variants->take(8)->map(fn ($v): array => [
                'id' => $v->id,
                'sku' => $v->sku,
                'attributes' => $v->attributes,
                'price' => $v->price ? (float) $v->price : (float) $p->price,
                'stock' => (int) $v->stock,
            ])->values()->all(),
        ])->all();
    }
}
