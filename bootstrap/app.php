<?php

use App\Http\Middleware\EnsureAuthenticationMode;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
        $middleware->api(append: [
            'throttle:api',
        ]);
        $middleware->prependToPriorityList(
            [AuthenticatesRequests::class, ThrottleRequests::class],
            EnsureAuthenticationMode::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
            $isOtpCooldown = ($exception->getHeaders()['X-Rate-Limit-Reason'] ?? null) === 'otp-cooldown';

            return response()->json([
                'code' => $isOtpCooldown ? 'OTP_RESEND_NOT_READY' : 'RATE_LIMITED',
                'message' => $exception->getMessage(),
                'retryAfter' => $retryAfter,
                'resendAvailableAt' => $isOtpCooldown ? now()->addSeconds($retryAfter)->toISOString() : null,
            ], 429, ['Retry-After' => (string) $retryAfter]);
        });
    })->create();
