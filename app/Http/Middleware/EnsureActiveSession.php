<?php

namespace App\Http\Middleware;

use App\Models\User\AuthSession;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $sessionId = auth('api')->payload()->get('sid');
        } catch (JWTException) {
            return $next($request);
        }
        if ($sessionId) {
            $session = AuthSession::whereKey($sessionId)
                ->where('user_id', auth('api')->id())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first();
            abort_unless($session, 401, 'Session has expired or been revoked.');
            $session->update(['last_used_at' => now()]);
        }

        return $next($request);
    }
}
