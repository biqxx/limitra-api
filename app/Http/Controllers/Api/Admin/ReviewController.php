<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Review\ModerateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product\Review;
use App\Services\Product\ReviewAggregateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReviewController extends BaseController
{
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
}
