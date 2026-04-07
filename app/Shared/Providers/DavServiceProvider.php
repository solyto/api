<?php

namespace App\Shared\Providers;

use App\Dav\Factories\DavServerFactory;
use Illuminate\Support\ServiceProvider;

class DavServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(DavServerFactory::class, function ($app) {
            return new DavServerFactory($app['db']);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
