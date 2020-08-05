<?php

namespace Cybex\Protector;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProtectorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerRoutes();

        // Publish package config to app config space.
        $this->publishes([__DIR__ . '/../config/protector.php' => config_path('protector.php')]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/protector.php', 'protector');

        // Register the main class to use with the facade
        $this->app->singleton('protector', function () {
            return new Protector;
        });
    }

    protected function registerRoutes()
    {
        Route::get(config('protector.routeEndpoint') ?: '/protector/exportDump', [Protector::class, 'generateFile'])->middleware(config('protector.routeMiddleware'));
    }
}
