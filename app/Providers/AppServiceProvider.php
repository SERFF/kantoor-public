<?php

namespace App\Providers;

use App\Office\Office;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Office::class);
    }

    public function boot(): void
    {
        Passport::authorizationView(fn ($parameters) => view('mcp.authorize', $parameters));

        Passport::tokensExpireIn(now()->addDays(30));
        Passport::refreshTokensExpireIn(now()->addDays(180));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('hooks', fn (Request $request) => Limit::perMinute(1200)->by(hash('sha256', (string) $request->bearerToken()) ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
