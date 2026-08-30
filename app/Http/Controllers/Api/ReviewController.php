<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Review\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Product\Review;
use App\Services\Product\ReviewAggregateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReviewController extends BaseController
{
    public function update(
        UpdateReviewRequest $request,
        Review $review,
        ReviewAggregateService $aggregates,
    ): JsonResponse {
        $this->authorizeOwner($review);
        $data = $request->validated();
        if ($data === []) {
            throw ValidationException::withMessages(['review' => ['At least one review field is required.']]);
        }

        DB::transaction(function () use ($review, $data, $aggregates) {
            $wasPublished = $review->status === 'published';
            $review->update(array_merge($data, [
                'status' => 'pending',
                'moderated_by' => null,
                'moderation_reason' => null,
                'moderated_at' => null,
            ]));
            if ($wasPublished) {
                $aggregates->refresh($review->product_id);
            }
        });

        return $this->success(
            new ReviewResource($review->fresh()->load(['user.profile', 'images'])),
            'Review updated and submitted for moderation.',
        );
    }

    public function destroy(Review $review, ReviewAggregateService $aggregates): Response
    {
        $this->authorizeOwnerOrStaff($review);
        $paths = $review->images()->pluck('path')->all();

        DB::transaction(function () use ($review, $aggregates) {
            $wasPublished = $review->status === 'published';
            $review->delete();
            if ($wasPublished) {
                $aggregates->refresh($review->product_id);
            }
        });
        Storage::disk('public')->delete($paths);

        return response()->noContent();
    }

    public function helpful(Review $review): JsonResponse
    {
        $userId = (int) auth('api')->id();

        $result = DB::transaction(function () use ($review, $userId) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'published') {
                abort(404);
            }
            if ($locked->user_id === $userId) {
                throw ValidationException::withMessages(['review' => ['You cannot mark your own review as helpful.']]);
            }
            $vote = $locked->helpfulVotes()->firstOrCreate(['user_id' => $userId]);
            if ($vote->wasRecentlyCreated) {
                $locked->increment('helpful_count');
            }

            return ['helpful_count' => (int) $locked->fresh()->helpful_count];
        });

        return $this->success($result, 'Review marked as helpful.');
    }

    private function authorizeOwner(Review $review): void
    {
        if ($review->user_id !== auth('api')->id()) {
            abort(403, 'Forbidden.');
        }
    }

    private function authorizeOwnerOrStaff(Review $review): void
    {
        $user = auth('api')->user();
        if ($review->user_id !== $user->id && ! $user->isStaff()) {
            abort(403, 'Forbidden.');
        }
    }
}
