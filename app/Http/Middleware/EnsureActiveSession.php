<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthSessionManager;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public function __construct(private readonly AuthSessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
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
