<?php

use App\Http\Middleware\EnsureAuthenticationMode;
use App\Http\Middleware\ForceJsonApiResponse;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.mode' => EnsureAuthenticationMode::class,
        ]);
        $middleware->api(prepend: [
            ForceJsonApiResponse::class,
        ], append: [
            'throttle:api',
        ]);
        $middleware->prependToPriorityList(
            [AuthenticatesRequests::class, ThrottleRequests::class],
            ForceJsonApiResponse::class,
        );
        $middleware->prependToPriorityList(
            [AuthenticatesRequests::class, ThrottleRequests::class],
            EnsureAuthenticationMode::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_ERROR',
                'The given data was invalid.',
                $exception->errors(),
                status: 422,
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('UNAUTHENTICATED', 'You must be authenticated to access this endpoint.', status: 401);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('RESOURCE_NOT_FOUND', 'The requested resource was not found.', status: 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
            $isOtpCooldown = ($exception->getHeaders()['X-Rate-Limit-Reason'] ?? null) === 'otp-cooldown';

            return ApiResponse::error(
                $isOtpCooldown ? 'OTP_RESEND_NOT_READY' : 'RATE_LIMITED',
                $exception->getMessage(),
                errors: null,
                meta: [
                    'retryAfter' => $retryAfter,
                    'resendAvailableAt' => $isOtpCooldown
                        ? now()->addSeconds($retryAfter)->toISOString()
                        : null,
                ],
                status: 429,
            )->withHeaders([
                'Retry-After' => (string) $retryAfter,
            ]);
        });
    })->create();
