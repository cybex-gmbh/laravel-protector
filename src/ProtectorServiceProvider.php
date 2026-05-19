<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\SchemaState\MariaDb\MariaDbSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\MySql\MySqlSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\Postgres\PostgresSchemaStateProxy;
use Cybex\Protector\Classes\SodiumCrypter;
use Cybex\Protector\Commands\CreateKeys;
use Cybex\Protector\Commands\CreateToken;
use Cybex\Protector\Commands\ExportDump;
use Cybex\Protector\Commands\ImportDump;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Illuminate\Database\Schema\MariaDbSchemaState;
use Illuminate\Database\Schema\MySqlSchemaState;
use Illuminate\Database\Schema\PostgresSchemaState;
use Illuminate\Support\Facades\DB;
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
        ], ['protector', 'protector.config']);

        $this->publishMigrations();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Automatically apply the package configuration.
        $this->mergeConfigFrom(__DIR__ . '/../config/protector.php', 'protector');

        // Register the Protector class as a singleton and bind the alias.
        // Scoped to the request lifecycle to ensure a new instance is created for each request (e.g. for Octane).
        $this->app->scoped(Protector::class, Protector::class);
        $this->app->bind('protector', Protector::class);

        $this->app->singleton(CrypterContract::class, SodiumCrypter::class);
        $this->app->bind(ProtectorConfigContract::class, ProtectorConfig::class);
        $this->app->bind(ProtectorConfiguratorContract::class, ProtectorConfigurator::class);

        // Register the SchemaState proxy classes.
        $this->app->bind(SchemaStateProxyContract::class, function ($app, array $params): SchemaStateProxyContract {
            $connectionName = $params['connection'];
            $protectorConfig = $params['protectorConfig'];

            $connection = DB::connection($connectionName);
            $schemaState = $connection->getSchemaState();

            return match (get_class($schemaState)) {
                MariaDbSchemaState::class => app(MariaDbSchemaStateProxy::class, ['schemaState' => $schemaState, 'config' => $protectorConfig]),
                MySqlSchemaState::class => app(MySqlSchemaStateProxy::class, ['schemaState' => $schemaState, 'config' => $protectorConfig]),
                PostgresSchemaState::class => app(PostgresSchemaStateProxy::class, ['schemaState' => $schemaState, 'config' => $protectorConfig]),
                //            SqliteSchemaState::class => app('SqliteSchemaStateProxy', ['schemaState' => $schemaState, 'config' => $protectorConfig]),
                default => throw new UnsupportedDatabaseException('Unsupported database schema state: ' . class_basename($schemaState)),
            };
        });
    }

    protected function registerRoutes(): void
    {
        Route::post(config('protector.server.dumpEndpointRoute'))
            ->middleware(config('protector.server.routeMiddleware'))
            ->name('protector.server.dump')
            ->uses([Protector::class, 'prepareFileDownloadResponse']);
    }

    /**
     * Publish the package's migrations.
     *
     * @return void
     */
    protected function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His', time());
        $migrationName = 'add_public_key_to_users_table.php';

        $stub = sprintf('%s/../Migrations/%s', __DIR__, $migrationName);
        $target = $this->app->databasePath(sprintf('migrations/%s_%s', $timestamp, $migrationName));

        $this->publishes([$stub => $target], ['protector', 'protector.migrations']);
    }
}
