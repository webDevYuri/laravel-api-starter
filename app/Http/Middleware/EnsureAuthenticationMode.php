<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationMode
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        if (config('auth.mode') !== $mode) {
            return response()->json([
                'code' => 'AUTHENTICATION_METHOD_DISABLED',
                'message' => ucfirst($mode).' authentication is not enabled for this application.',
            ], 403);
        }

        return $next($request);
    }
}
