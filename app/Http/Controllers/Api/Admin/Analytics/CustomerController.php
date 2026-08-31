<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Analytics\DateRangeRequest;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

class CustomerController extends BaseController
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function __invoke(DateRangeRequest $request): JsonResponse
    {
        $data = $this->analytics->getCustomerStats(
            $request->from(),
            $request->to(),
            $request->groupBy()
        );

        return $this->success($data);
    }
}
