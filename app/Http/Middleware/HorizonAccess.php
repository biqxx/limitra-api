<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HorizonAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) config('horizon.auth.username');
        $password = (string) config('horizon.auth.password');

        if ($username === '' || $password === '') {
            if (app()->environment('local', 'testing')) {
                return $next($request);
            }

            abort(403, 'Horizon dashboard credentials are not configured.');
        }

        if (! hash_equals($username, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Limitra Horizon"',
            ]);
        }

        return $next($request);
    }
}
