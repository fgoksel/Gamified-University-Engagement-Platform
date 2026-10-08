<?php

namespace App\Providers;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Policies\AdminPolicy;
use App\Policies\RoleAssignmentPolicy;
use App\Policies\RoleDefinitionPolicy;
use App\Policies\TopicPolicy;
use App\Services\TopicAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One resolver per request, so every check in a request sees the same data.
        $this->app->scoped(TopicAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every new password must be at least 8 characters (Technical Specification 8.2).
        // Use it in validation as: 'password' => ['required', Password::defaults()]
        Password::defaults(fn () => Password::min(8));

        // Admin self-lock prevention policy (Technical Specification Table 25, Task #32).
        Gate::policy(User::class, AdminPolicy::class);

        // Topics, reusable roles and role assignments.
        Gate::policy(Topic::class, TopicPolicy::class);
        Gate::policy(RoleDefinition::class, RoleDefinitionPolicy::class);
        Gate::policy(RoleAssignment::class, RoleAssignmentPolicy::class);

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
        // Keyed by email + IP, so students sharing campus Wi-Fi don't lock each other out.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('qr-redeem', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
