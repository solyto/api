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
use Illuminate\Validation\Rules\Password;
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
        Password::defaults(fn () => Password::min(12)->uncompromised());
        User::observe(UserObserver::class);
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->email ?: $request->ip());
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}
