<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Review\ModerateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product\Review;
use App\Services\Product\ReviewAggregateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReviewController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'published', 'rejected', 'flagged'])],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Review::query()
            ->with(['user.profile', 'images'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['product_id'] ?? null, fn ($q, $productId) => $q->where('product_id', $productId))
            ->when($validated['rating'] ?? null, fn ($q, $rating) => $q->where('rating', $rating))
            ->when($validated['q'] ?? null, function ($q, $term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', "%{$term}%")
                        ->orWhere('body', 'like', "%{$term}%");
                });
            })
            ->latest('id');

        $paginator = $query->paginate($validated['per_page'] ?? 20);

        return $this->success([
            'items' => ReviewResource::collection($paginator->getCollection()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(Review $review): JsonResponse
    {
        $review->load(['user.profile', 'images']);

        return $this->success(new ReviewResource($review));
    }

    public function moderate(
        ModerateReviewRequest $request,
        Review $review,
        ReviewAggregateService $aggregates,
    ): JsonResponse {
        $data = $request->validated();

        DB::transaction(function () use ($review, $data, $aggregates) {
            $review->update([
                'status' => $data['status'],
                'moderated_by' => auth('api')->id(),
                'moderation_reason' => $data['reason'] ?? null,
                'moderated_at' => now(),
            ]);
            $aggregates->refresh($review->product_id);
        });

        return $this->success(
            new ReviewResource($review->fresh()->load(['user.profile', 'images'])),
            'Review moderation updated.',
        );
    }

    public function destroy(Review $review, ReviewAggregateService $aggregates): JsonResponse
    {
        $productId = $review->product_id;

        DB::transaction(function () use ($review, $productId, $aggregates) {
            $review->delete();
            $aggregates->refresh($productId);
        });

        return $this->success(null, 'Review deleted.');
    }
}
