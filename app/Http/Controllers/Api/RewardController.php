<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Reward\IndexRewardsRequest;
use App\Http\Requests\Reward\SpinRewardRequest;
use App\Http\Resources\RewardCampaignResource;
use App\Http\Resources\RewardSpinResource;
use App\Models\Reward\RewardSpin;
use App\Services\Reward\SpinRewardService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;

class RewardController extends BaseController
{
    public function index(
        IndexRewardsRequest $request,
        SpinRewardService $rewards,
        BusinessSettingsService $settings,
    ): JsonResponse {
        $data = $request->validated();
        $spins = RewardSpin::query()
            ->where('user_id', $request->user()->id)
            ->with(['campaign', 'prize', 'walletTransaction'])
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);
        $enabled = (bool) $settings->value('rewards.enabled');
        $campaign = $enabled ? $rewards->activeCampaign($request->user()) : null;

        return $this->success([
            'enabled' => $enabled,
            'campaign' => $campaign ? new RewardCampaignResource($campaign) : null,
            'items' => RewardSpinResource::collection($spins->getCollection()),
            'pagination' => [
                'current_page' => $spins->currentPage(),
                'last_page' => $spins->lastPage(),
                'per_page' => $spins->perPage(),
                'total' => $spins->total(),
            ],
        ]);
    }

    public function spin(SpinRewardRequest $request, SpinRewardService $rewards): JsonResponse
    {
        $result = $rewards->spin($request->user(), $request->validated('idempotency_key'));

        return $this->success(
            new RewardSpinResource($result['spin']),
            $result['replayed'] ? 'Reward spin replayed.' : 'Reward spin completed.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function redeem(IndexRewardsRequest $request, RewardSpin $reward): JsonResponse
    {
        if ($reward->user_id !== $request->user()->id) {
            abort(403);
        }

        $reward->load(['campaign', 'prize', 'walletTransaction']);

        return $this->success(
            new RewardSpinResource($reward),
            $reward->status === 'won'
                ? 'Cash rewards are credited automatically.'
                : 'This spin has no redeemable reward.',
        );
    }
}
