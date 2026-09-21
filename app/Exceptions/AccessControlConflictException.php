<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiErrorResponse;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessControlConflictException extends Exception implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return ApiErrorResponse::make(ApiErrorCode::Conflict, $this->getMessage(), 409);
    }
}
