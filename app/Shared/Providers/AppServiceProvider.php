<?php

namespace App\Shared\Providers;

use App\Api\Calendars\Services\CalendarService;
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

        // One instance per request or job, so every caller shares one DAV backend and
        // forgetRemoteState() reaches the external calendar links that backend holds.
        $this->app->scoped(CalendarService::class);

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
        Gate::define('viewPulse', fn (User $user): bool => $user->isSuperAdmin());

        // The only place restricted (non-`*`) tokens are scoped: they work on `widgets.*` routes and nowhere else.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $isUnrestricted = in_array('*', $token->abilities ?? [], true);
            $isWidgetRoute = (bool) request()->route()?->named('widgets.*');

            return $isValid && ($isUnrestricted || $isWidgetRoute);
        });

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
            return Limit::perHour(5)->by($this->userOrIp($request));
        });

        RateLimiter::for('feeds-import', function (Request $request) {
            return Limit::perHour(20)->by($request->user()->id);
        });

        RateLimiter::for('calendars-import', function (Request $request) {
            return Limit::perHour(20)->by($request->user()->id);
        });

        RateLimiter::for('contacts-import', function (Request $request) {
            return Limit::perHour(20)->by($request->user()->id);
        });

        // Each of these asks the user's own CalDAV server (discovery), up to a timeout per request; one connect takes about three.
        RateLimiter::for('external-calendars', function (Request $request) {
            return Limit::perMinute(20)->by($this->userOrIp($request));
        });

        RateLimiter::for('widget-token', function (Request $request) {
            return Limit::perMinute(10)->by($this->userOrIp($request));
        });

        // Every new dev request pushes an ntfy alert to the admins.
        RateLimiter::for('dev-requests', function (Request $request) {
            return Limit::perHour(10)->by($request->user()->id);
        });

        RateLimiter::for('clipboard-files', function (Request $request) {
            // Counts stored files only: a rejected upload (wrong type, too large) doesn't use up the quota.
            return Limit::perDay(config('clipboard.max_files_per_day'))
                ->by($request->user()->id)
                ->after(fn ($response) => $response->isSuccessful());
        });

        RateLimiter::for('widgets', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken ? 'token:' . $token->id : $this->userOrIp($request);

            return Limit::perMinute(60)->by($key);
        });
    }

    private function userOrIp(Request $request): ?string
    {
        return $request->user()?->id ?: $request->ip();
    }
}
