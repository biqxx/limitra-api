<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateApplicationController extends BaseController
{
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user('api');

        if (Affiliate::query()->where('user_id', $user->id)->exists()) {
            return $this->error('You are already registered as an affiliate.', 409);
        }

        $pending = AffiliateApplication::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($pending) {
            return $this->error('You already have a pending affiliate application under review.', 422);
        }

        $validated = $request->validate([
            'promotion_channels' => ['required', 'array', 'min:1'],
            'promotion_channels.*' => ['string', 'max:100'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = AffiliateApplication::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'promotion_channels' => $validated['promotion_channels'],
            'website_url' => $validated['website_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->success([
            'application' => $application,
            'expected_review_days' => 3,
        ], 'Affiliate application submitted successfully.', 201);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user('api');

        $application = AffiliateApplication::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return $this->success([
            'has_application' => $application !== null,
            'application' => $application,
        ], 'Affiliate application status retrieved.');
    }
}
