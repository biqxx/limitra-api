<?php

namespace App\Http\Controllers\Api;

use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicSettingController extends BaseController
{
    #[OA\Get(
        path: '/settings/public',
        tags: ['Settings'],
        summary: 'Get public storefront settings',
        description: 'Returns client-safe business configuration including currency, return policy, delivery rules, and enabled features.',
        responses: [
            new OA\Response(response: 200, description: 'Public settings grouped by domain', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(BusinessSettingsService $settings): JsonResponse
    {
        return $this->success($settings->publicSettings());
    }
}
