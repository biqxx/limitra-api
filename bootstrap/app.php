<?php

use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\TrackAnalytics;
use App\Providers\AIServiceProvider;
use App\Providers\SocialServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AIServiceProvider::class,
        SocialServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'track.analytics' => TrackAnalytics::class,
            'active.session' => EnsureActiveSession::class,
        ]);

        // Append analytics tracking to all API routes.
        $middleware->appendToGroup('api', TrackAnalytics::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        // Return JSON for unauthenticated API requests instead of redirecting.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
