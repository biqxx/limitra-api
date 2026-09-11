<?php

use App\Enums\ApiErrorCode;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\TrackAnalytics;
use App\Http\Responses\ApiErrorResponse;
use App\Providers\AIServiceProvider;
use App\Providers\SocialServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

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
        $exceptions->dontReportDuplicates();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                ApiErrorCode::ValidationFailed,
                'The given data was invalid.',
                422,
                $exception->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(ApiErrorCode::Unauthenticated, 'Unauthenticated.', 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(ApiErrorCode::Forbidden, 'This action is unauthorized.', 403);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(ApiErrorCode::ResourceNotFound, 'Resource not found.', 404);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(ApiErrorCode::ResourceNotFound, 'Resource not found.', 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                ApiErrorCode::MethodNotAllowed,
                'Method not allowed.',
                405,
                headers: $exception->getHeaders(),
            );
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                ApiErrorCode::TooManyRequests,
                'Too many requests.',
                429,
                headers: $exception->getHeaders(),
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = $status >= 500
                ? 'Server error.'
                : ($exception->getMessage() ?: 'Request failed.');

            return ApiErrorResponse::forStatus(
                $message,
                $status,
                headers: $exception->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(ApiErrorCode::ServerError, 'Server error.', 500);
        });
    })->create();
