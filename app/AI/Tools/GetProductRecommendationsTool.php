<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Analytics\CartEvent;
use App\Models\Cart\CartItem;
use App\Models\Cart\Favorite;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;

class GetProductRecommendationsTool implements Tool
{
    public function getName(): string
    {
        return 'get_product_recommendations';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Fetch up to 25 recommended products for a given keyword, ranked by sales, cart activity, favourites, and the user\'s personal history. Use this whenever a user asks about any product type. Return the results to ask the user a clarifying question, then recommend the best 5.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The product keyword the user mentioned (e.g. "phone", "laptop", "shoes").',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        $query = $arguments['query'];
        $userId = $context->userId;

        // ── 1. Candidate products matching the query ──────────────────────────
        $candidateIds = Product::where('name', 'like', "%{$query}%")
            ->where('stock', '>', 0)
            ->pluck('id')
            ->toArray();

        if (empty($candidateIds)) {
            return [
                'products' => [],
                'message' => "No in-stock products found matching '{$query}'.",
            ];
        }

        // ── 2. Top 25 most sold (order_items) ─────────────────────────────────
        $soldMap = OrderItem::selectRaw('product_id, COUNT(*) as cnt')
            ->whereIn('product_id', $candidateIds)
            ->groupBy('product_id')
            ->orderByDesc('cnt')
            ->limit(25)
            ->pluck('cnt', 'product_id')
            ->toArray();

        // ── 3. Top 25 most added to cart (cart_events) ────────────────────────
        $cartMap = CartEvent::selectRaw('product_id, COUNT(*) as cnt')
            ->where('event_type', 'add')
            ->whereIn('product_id', $candidateIds)
            ->groupBy('product_id')
            ->orderByDesc('cnt')
            ->limit(25)
            ->pluck('cnt', 'product_id')
            ->toArray();

        // ── 4. Top 25 most favourited ─────────────────────────────────────────
        $favMap = Favorite::selectRaw('product_id, COUNT(*) as cnt')
            ->whereIn('product_id', $candidateIds)
            ->groupBy('product_id')
            ->orderByDesc('cnt')
            ->limit(25)
            ->pluck('cnt', 'product_id')
            ->toArray();

        // ── 5. Personalised history (user's orders + favourites + cart) ───────
        $historyIds = [];

        if ($userId) {
            $recentOrdered = OrderItem::whereHas('order', fn ($q) => $q->where('user_id', $userId))
                ->whereIn('product_id', $candidateIds)
                ->orderByDesc('created_at')
                ->limit(25)
                ->pluck('product_id')
                ->toArray();

            $recentFavoured = Favorite::where('user_id', $userId)
                ->whereIn('product_id', $candidateIds)
                ->orderByDesc('created_at')
                ->limit(25)
                ->pluck('product_id')
                ->toArray();

            $recentCarted = CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $userId))
                ->whereIn('product_id', $candidateIds)
                ->orderByDesc('created_at')
                ->limit(25)
                ->pluck('product_id')
                ->toArray();

            $historyIds = array_values(array_unique(
                array_merge($recentOrdered, $recentFavoured, $recentCarted)
            ));
        }

        // ── 6. Union all sources, deduplicate, cap at 25 ──────────────────────
        $union = array_slice(
            array_values(array_unique(array_merge(
                array_keys($soldMap),
                array_keys($cartMap),
                array_keys($favMap),
                $historyIds,
            ))),
            0, 25
        );

        // ── 7. Fetch full product details ─────────────────────────────────────
        $products = Product::with('category', 'subcategory')
            ->whereIn('id', $union)
            ->get();

        // ── 8. Build response with signals ────────────────────────────────────
        return [
            'total_found' => count($union),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'stock' => $p->stock,
                'category' => $p->category?->name,
                'subcategory' => $p->subcategory?->name,
                'signals' => [
                    'order_count' => $soldMap[$p->id] ?? 0,
                    'cart_count' => $cartMap[$p->id] ?? 0,
                    'favourite_count' => $favMap[$p->id] ?? 0,
                    'in_user_history' => in_array($p->id, $historyIds),
                ],
            ])->toArray(),
        ];
    }
}
