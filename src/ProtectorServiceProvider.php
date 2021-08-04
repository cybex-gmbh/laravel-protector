<?php

namespace Cybex\Protector;

use Cybex\Protector\Commands\CreateKeys;
use Cybex\Protector\Commands\CreateToken;
use Cybex\Protector\Commands\ExportDump;
use Cybex\Protector\Commands\ImportDump;
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
            CreateKeys::class,
            CreateToken::class,
            ExportDump::class,
            ImportDump::class,
        ]);

        // Publish package config to app config space.
        $this->publishes([
            __DIR__ . '/../config/protector.php' => config_path('protector.php'),
        ], 'protector.config');

        $this->publishMigrations();
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
        Route::post(config('protector.dumpEndpointRoute'))
            ->middleware(config('protector.routeMiddleware'))
            ->name('protectorDumpEndpointRoute')
            ->uses([Protector::class, 'generateFileDownloadResponse']);
    }

    /**
     * Publish the package's migrations.
     *
     * @return void
     */
    protected function publishMigrations()
    {
        if (class_exists('AddPublicKeyToUsersTable')) {
            return;
        }

        $timestamp = date('Y_m_d_His', time());
        $migrationName = 'add_public_key_to_users_table.php';

        $stub   = sprintf('%s/../Migrations/%s', __DIR__, $migrationName);
        $target = $this->app->databasePath(sprintf('migrations/%s_%s', $timestamp, $migrationName));

        $this->publishes([$stub => $target], 'protector.migrations');
    }
}
