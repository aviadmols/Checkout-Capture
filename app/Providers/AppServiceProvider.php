<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('captures', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(600)->by('key:'.$request->input('key')),
        ]);

        // Activations drive billing, so one visitor can't inflate a shop's count much.
        RateLimiter::for('activations', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perDay(100)->by('ip-day:'.$request->ip()),
        ]);

        RateLimiter::for('shopify-app', fn (Request $request) => Limit::perMinute(120)->by('shopify-app:'.$request->ip()));

        RateLimiter::for('carts', fn (Request $request) => Limit::perMinute(30)->by('ip:'.$request->ip()));

        RateLimiter::for('widget-config', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
