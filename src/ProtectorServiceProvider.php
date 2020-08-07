<?php

namespace Cybex\Protector;

use Cybex\Protector\Commands\ExportDump;
use Cybex\Protector\Commands\ImportDump;
use Cybex\Protector\Middleware\CheckToken;
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
        $this->commands([
            ExportDump::class,
            ImportDump::class,
        ]);
        $this->loadMigrationsFrom(__DIR__.'/../src/Migrations');
        $this->app['router']->aliasMiddleware('checkToken', CheckToken::class);

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
        Route::post(config('protector.routeEndpoint'), [
            Protector::class,
            'generateFileDownloadResponse',
        ])->middleware(config('protector.routeMiddleware'));
    }
}
