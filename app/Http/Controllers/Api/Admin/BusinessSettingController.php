<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Http\Resources\Settings\BusinessSettingChangeResource;
use App\Http\Resources\Settings\BusinessSettingResource;
use App\Models\Settings\BusinessSetting;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessSettingController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['sometimes', 'string', 'max:50'],
        ]);
        $settings = BusinessSetting::query()
            ->with('updatedBy')
            ->when($data['group'] ?? null, fn ($query, string $group) => $query->where('group', $group))
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        return $this->success(BusinessSettingResource::collection($settings));
    }

    public function update(
        UpdateBusinessSettingsRequest $request,
        BusinessSettingsService $settings,
    ): JsonResponse {
        $updated = $settings->update($request->validated('settings'), $request->user());

        return $this->success(
            BusinessSettingResource::collection($updated),
            'Business settings updated.',
        );
    }

    public function history(Request $request, BusinessSetting $businessSetting): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $changes = $businessSetting->changes()
            ->with('changedBy')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => BusinessSettingChangeResource::collection($changes->getCollection()),
            'pagination' => [
                'current_page' => $changes->currentPage(),
                'last_page' => $changes->lastPage(),
                'per_page' => $changes->perPage(),
                'total' => $changes->total(),
            ],
        ]);
    }
}
