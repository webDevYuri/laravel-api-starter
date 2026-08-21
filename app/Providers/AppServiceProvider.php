<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier() ?? $request->ip()
            );
        });

        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $registrationId = $request->input('registrationId');
            $identifier = is_string($email)
                ? Str::lower($email)
                : (is_string($registrationId) ? $registrationId : 'anonymous');
            $route = $request->route()?->uri() ?? $request->path();

            return Limit::perMinute(5)->by(
                implode('|', [$request->ip(), $route, $identifier])
            );
        });
    }
}
