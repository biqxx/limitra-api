<?php

namespace App\Services\Product;

use App\Models\Product\Product;
use App\Models\Product\Review;

class ReviewAggregateService
{
    public function refresh(int $productId): void
    {
        $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->firstOrFail();
        $reviews = Review::query()
            ->where('product_id', $productId)
            ->where('status', 'published');

        $count = (clone $reviews)->count();
        $average = $count > 0 ? (float) $reviews->avg('rating') : 0;

        $product->updateQuietly([
            'review_count' => $count,
            'average_rating' => round($average, 2),
        ]);
    }
}
