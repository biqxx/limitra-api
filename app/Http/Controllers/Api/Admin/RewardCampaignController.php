<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Reward\StoreRewardCampaignRequest;
use App\Http\Requests\Reward\UpdateRewardCampaignRequest;
use App\Http\Resources\RewardCampaignResource;
use App\Http\Resources\RewardSpinResource;
use App\Models\Reward\RewardCampaign;
use App\Services\Reward\RewardCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RewardCampaignController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused', 'ended'])],
            'q' => ['sometimes', 'string', 'max:160'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $campaigns = RewardCampaign::query()
            ->with('prizes')
            ->withCount('spins')
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['q'] ?? null, fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => RewardCampaignResource::collection($campaigns->getCollection()),
            'pagination' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(StoreRewardCampaignRequest $request, RewardCampaignService $campaigns): JsonResponse
    {
        $campaign = $campaigns->create($request->validated(), $request->user());

        return $this->success(new RewardCampaignResource($campaign), 'Reward campaign created.', 201);
    }

    public function update(
        UpdateRewardCampaignRequest $request,
        RewardCampaign $campaign,
        RewardCampaignService $campaigns,
    ): JsonResponse {
        $campaign = $campaigns->update($campaign, $request->validated(), $request->user());

        return $this->success(new RewardCampaignResource($campaign), 'Reward campaign updated.');
    }

    public function wins(Request $request, RewardCampaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['won', 'no_reward'])],
            'prize_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $spins = $campaign->spins()
            ->with(['campaign', 'prize', 'walletTransaction', 'user:id,username,email'])
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['prize_id'] ?? null, fn ($query, int $prizeId) => $query->where('reward_prize_id', $prizeId))
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => RewardSpinResource::collection($spins->getCollection()),
            'pagination' => [
                'current_page' => $spins->currentPage(),
                'last_page' => $spins->lastPage(),
                'per_page' => $spins->perPage(),
                'total' => $spins->total(),
            ],
        ]);
    }
}
