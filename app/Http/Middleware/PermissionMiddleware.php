<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Auth\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$requiredPermissions): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->permissions->allowsAll($user, $requiredPermissions)) {
            return ApiErrorResponse::make(
                ApiErrorCode::Forbidden,
                'Forbidden. Insufficient permissions.',
                403,
            );
        }

        return $next($request);
    }
}
