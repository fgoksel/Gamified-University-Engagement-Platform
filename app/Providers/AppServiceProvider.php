<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Every new password must be at least 8 characters (Technical Specification 8.2).
        // Use it in validation as: 'password' => ['required', Password::defaults()]
        Password::defaults(fn () => Password::min(8));

        $this->configureRateLimiting();
    }

    /**
     * Brute-force protection (Technical Specification 8.2).
     *
     * Use on routes as ->middleware('throttle:login') or ->middleware('throttle:qr-redeem').
     * The Filament admin login has its own limit of 5 attempts per minute.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('qr-redeem', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
