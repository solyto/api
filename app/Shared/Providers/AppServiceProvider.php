<?php

namespace App\Shared\Providers;

use App\Api\Users\Models\User;
use App\Api\Users\Observers\UserObserver;
use App\Api\Users\Policies\UserPolicy;
use App\Shared\Services\Images\ImgproxyDriver;
use App\Shared\Services\Images\InterventionDriver;
use App\Shared\Services\Images\ImageTransformationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\TelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(ImageTransformationService::class, function () {
            $driver = config('services.image.driver', 'imgproxy') === 'intervention'
                ? new InterventionDriver()
                : new ImgproxyDriver();

            return new ImageTransformationService($driver);
        });
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(config('auth.policy.min_length'))
                                            ->when(config('auth.policy.uncompromised'), fn ($rule) => $rule->uncompromised()));

        User::observe(UserObserver::class);
        Gate::policy(User::class, UserPolicy::class);

        // The only place restricted (non-`*`) tokens are scoped: they work on `widgets.*` routes and nowhere else.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && (in_array('*', $token->abilities ?? [], true) || (bool) request()->route()?->named('widgets.*'))
        );

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->email ?: $request->ip());
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        RateLimiter::for('auth-password-reset', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        RateLimiter::for('auth-verification-resend', function (Request $request) {
            return [
                Limit::perHour(3)->by('resend:email:' . Str::lower((string) $request->email)),
                Limit::perHour(10)->by('resend:ip:' . $request->ip()),
            ];
        });

        RateLimiter::for('auth-passkey', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('account-deletion', function (Request $request) {
            return Limit::perHour(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('widget-token', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('widgets', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(60)->by($token instanceof PersonalAccessToken ? 'token:' . $token->id : ($request->user()?->id ?: $request->ip()));
        });
    }
}
