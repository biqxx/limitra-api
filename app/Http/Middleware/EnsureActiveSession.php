<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Auth\AuthSessionManager;
use App\Services\Auth\UserStatusService;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public function __construct(
        private readonly AuthSessionManager $sessions,
        private readonly UserStatusService $statuses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if ($user instanceof User && $this->statuses->isSuspended($user)) {
            return ApiErrorResponse::make(
                ApiErrorCode::AccountSuspended,
                'This account is suspended.',
                403,
            );
        }

        try {
            $sessionId = auth('api')->payload()->get('sid');
        } catch (JWTException) {
            return $next($request);
        }
        if ($sessionId) {
            abort_unless(
                $this->sessions->isActive($sessionId, (int) auth('api')->id()),
                401,
                'Session has expired or been revoked.'
            );
        }

        return $next($request);
    }
}
