<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    protected function success(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(
        string $message = 'Error',
        int $status = 400,
        mixed $errors = null,
        ApiErrorCode|string|null $code = null,
    ): JsonResponse {
        return ApiErrorResponse::make(
            $code ?? ApiErrorCode::forStatus($status),
            $message,
            $status,
            $errors,
        );
    }
}
