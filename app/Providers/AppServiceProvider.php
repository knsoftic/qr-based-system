<?php

namespace App\Providers;

use App\Payments\Providers\ProviderRegistry;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(ProviderRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('merchant-api', function (Request $request) {
            $perMinute = max(1, (int) app(Settings::class)->get('api.rate_limit', 60));

            return [
                Limit::perMinute($perMinute)->by('merchant:'.($request->header('X-Api-Key') ?: $request->ip())),
                Limit::perMinute($perMinute * 2)->by('merchant-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verifier-api', fn (Request $request) => Limit::perMinute(120)->by('verifier:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(90)->by('checkout:'.$request->ip()));

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }
}
