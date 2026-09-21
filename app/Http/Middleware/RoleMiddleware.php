<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Protect a route by requiring one or more roles.
     *
     * Usage in routes:  ->middleware('role:admin')
     *                   ->middleware('role:admin,staff')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles)) {
            return ApiErrorResponse::make(
                ApiErrorCode::Forbidden,
                'Forbidden. Insufficient permissions.',
                403,
            );
        }

        return $next($request);
    }
}
