<?php

namespace Tests\Unit;

use App\Http\Middleware\HorizonAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    public function test_configured_dashboard_requires_matching_basic_credentials(): void
    {
        config([
            'horizon.auth.username' => 'operator',
            'horizon.auth.password' => 'secret-password',
        ]);
        $middleware = new HorizonAccess;
        $next = fn (): Response => response('allowed');

        $denied = $middleware->handle(Request::create('/horizon'), $next);
        $allowedRequest = Request::create('/horizon', server: [
            'PHP_AUTH_USER' => 'operator',
            'PHP_AUTH_PW' => 'secret-password',
        ]);
        $allowed = $middleware->handle($allowedRequest, $next);

        $this->assertSame(401, $denied->getStatusCode());
        $this->assertSame('Basic realm="Limitra Horizon"', $denied->headers->get('WWW-Authenticate'));
        $this->assertSame(200, $allowed->getStatusCode());
    }
}
