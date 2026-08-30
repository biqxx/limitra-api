<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductReviewController extends BaseController
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'sort' => ['sometimes', 'in:newest,oldest,highest_rating,lowest_rating,helpful'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $published = Review::query()
            ->where('product_id', $product->id)
            ->where('status', 'published');
        $counts = (clone $published)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');
        $breakdown = [];
        for ($rating = 5; $rating >= 1; $rating--) {
            $breakdown[(string) $rating] = (int) ($counts[$rating] ?? 0);
        }

        $reviews = (clone $published)
            ->with(['user.profile', 'images'])
            ->when($data['rating'] ?? null, fn ($query, $rating) => $query->where('rating', $rating));

        match ($data['sort'] ?? 'newest') {
            'oldest' => $reviews->oldest('id'),
            'highest_rating' => $reviews->orderByDesc('rating')->latest('id'),
            'lowest_rating' => $reviews->orderBy('rating')->latest('id'),
            'helpful' => $reviews->orderByDesc('helpful_count')->latest('id'),
            default => $reviews->latest('id'),
        };

        $page = $reviews->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => ReviewResource::collection($page->getCollection()),
            'summary' => [
                'average_rating' => number_format((float) $product->average_rating, 2, '.', ''),
                'review_count' => (int) $product->review_count,
                'rating_breakdown' => $breakdown,
            ],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function store(StoreReviewRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $storedPaths = [];

        try {
            $review = DB::transaction(function () use ($request, $product, $data, &$storedPaths) {
                $orderItem = OrderItem::with('order')->lockForUpdate()->findOrFail($data['order_item_id']);

                if ($orderItem->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['The order item does not belong to this product.'],
                    ]);
                }
                if ($orderItem->order->user_id !== auth('api')->id()) {
                    abort(403, 'Forbidden.');
                }
                if ($orderItem->order->fulfilment_status !== 'delivered') {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['Only delivered order items can be reviewed.'],
                    ]);
                }
                if (Review::withTrashed()->where('order_item_id', $orderItem->id)->exists()) {
                    throw ValidationException::withMessages([
                        'order_item_id' => ['This order item has already been reviewed.'],
                    ]);
                }

                $review = Review::create([
                    'product_id' => $product->id,
                    'order_item_id' => $orderItem->id,
                    'user_id' => auth('api')->id(),
                    'rating' => $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'],
                    'status' => 'pending',
                    'verified_purchase' => true,
                ]);

                foreach ($request->file('images', []) as $position => $image) {
                    $path = $image->store("reviews/{$review->id}", 'public');
                    if (! $path) {
                        throw new \RuntimeException('The review image could not be stored.');
                    }
                    $storedPaths[] = $path;
                    $review->images()->create(['path' => $path, 'sort_order' => $position]);
                }

                return $review;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        return $this->success(
            new ReviewResource($review->load(['user.profile', 'images'])),
            'Review submitted for moderation.',
            201,
        );
    }
}
