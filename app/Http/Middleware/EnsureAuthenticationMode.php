<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationMode
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        if (config('auth.mode') !== $mode) {
            return ApiResponse::error(
                'AUTHENTICATION_METHOD_DISABLED',
                ucfirst($mode).' authentication is not enabled for this application.',
                status: 403,
            );
        }

        return $next($request);
    }
}
