<?php

namespace App\Providers;

use App\Payments\Providers\ProviderRegistry;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        Paginator::defaultView('partials.pagination');

        // Behind Cloudflare / a load balancer: trust its forwarded IP and scheme.
        if ($proxies = config('gateway.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Generated links (checkout_url, callbacks, redirects) always use https in production.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('merchant-api', function (Request $request) {
            $perMinute = max(1, (int) app(Settings::class)->get('api.rate_limit', 60));

            return [
                Limit::perMinute($perMinute)->by('merchant:'.($request->header('X-Api-Key') ?: $request->ip())),
                Limit::perMinute($perMinute * 2)->by('merchant-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verifier-api', fn (Request $request) => Limit::perMinute(120)->by('verifier:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(90)->by('checkout:'.$request->ip()));

        RateLimiter::for('merchant-login', fn (Request $request) => [
            Limit::perMinute(5)->by('mlogin:'.strtolower((string) $request->input('username')).'|'.$request->ip()),
            Limit::perMinute(20)->by('mlogin-ip:'.$request->ip()),
        ]);

        // Password confirmations and credential checks in the merchant portal.
        RateLimiter::for('merchant-sensitive', fn (Request $request) => Limit::perMinute(10)->by('msens:'.($request->user('merchant')?->id ?? $request->ip())));

        // Test Pay-In creates real payment requests: keep it to a handful per minute.
        RateLimiter::for('payin-test', function (Request $request) {
            $who = $request->is('admin/*')
                ? 'admin:'.($request->user()?->id ?? $request->ip())
                : 'merchant:'.($request->user('merchant')?->id ?? $request->ip());

            return Limit::perMinute(6)->by('ptest:'.$who);
        });

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }
}
