<?php

namespace App\Http\Responses;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /** @param array<string, string|string[]> $headers */
    public static function make(
        ApiErrorCode|string $code,
        string $message,
        int $status,
        mixed $errors = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code instanceof ApiErrorCode ? $code->value : $code,
            'errors' => $errors,
        ], $status, $headers);
    }

    public static function forStatus(
        string $message,
        int $status,
        mixed $errors = null,
        array $headers = [],
    ): JsonResponse {
        return self::make(ApiErrorCode::forStatus($status), $message, $status, $errors, $headers);
    }
}
