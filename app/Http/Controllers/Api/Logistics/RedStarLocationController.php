<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Api\BaseController;
use App\Services\Logistics\RedStarService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class RedStarLocationController extends BaseController
{
    public function __construct(private readonly RedStarService $redStar) {}

    public function cities(): JsonResponse
    {
        try {
            return $this->success($this->redStar->cities());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }
    }

    public function towns(string $code): JsonResponse
    {
        try {
            return $this->success($this->redStar->deliveryTowns($code));
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }
    }

    public function dropOffLocations(string $code): JsonResponse
    {
        try {
            return $this->success($this->redStar->dropOffLocations($code));
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }
    }

    public function pickupTypes(): JsonResponse
    {
        try {
            return $this->success($this->redStar->pickupTypes());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }
    }
}

