<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Config\ProtectorConfig;
use Cybex\Protector\Classes\Config\ProtectorConfigurator;
use Cybex\Protector\Classes\DiskHelper;
use Cybex\Protector\Classes\SchemaState\MariaDb\MariaDbSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\MySql\MySqlSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\Postgres\PostgresSchemaStateProxy;
use Cybex\Protector\Classes\SodiumCrypter;
use Cybex\Protector\Commands\CleanupLocal;
use Cybex\Protector\Commands\CleanupStorage;
use Cybex\Protector\Commands\CreateKeys;
use Cybex\Protector\Commands\CreateToken;
use Cybex\Protector\Commands\DownloadDump;
use Cybex\Protector\Commands\ExportDump;
use Cybex\Protector\Commands\ImportDump;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Cybex\Protector\Enums\ExecutionMode;
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
        $this->registerCommands();

        $this->publishConfigs();
        $this->publishMigrations();

        $this->scheduleTasks();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeProtectorConfig();
        $this->mergeDiskConfig();

        $this->bindProtector();
        $this->bindHelpers();
        $this->bindSchemaStateProxies();
    }

    protected function registerRoutes(): void
    {
        Route::post(config('protector.server.dumpEndpointRoute'))
            ->middleware(config('protector.server.routeMiddleware'))
            ->name('protector.server.dump')
            ->uses([Protector::class, 'generateFileDownloadResponse']);
    }

    protected function registerCommands(): void
    {
        $this->commands([
            CleanupLocal::class,
            CleanupStorage::class,
            CreateKeys::class,
            CreateToken::class,
            DownloadDump::class,
            ExportDump::class,
            ImportDump::class,
        ]);
    }

    protected function publishConfigs(): void
    {
        foreach (['cleanup', 'client', 'dump', 'server'] as $config) {
            $this->publishes([
                sprintf('%s/../config/protector/%s.php', __DIR__, $config) => config_path(sprintf('protector/%s.php', $config)),
            ], ['protector', 'protector.config', sprintf('protector.config.%s', $config)]);
        }
    }

    protected function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His', time());
        $migrationName = 'add_public_key_to_users_table.php';

        $stub = sprintf('%s/../Migrations/%s', __DIR__, $migrationName);
        $target = $this->app->databasePath(sprintf('migrations/%s_%s', $timestamp, $migrationName));

        $this->publishes([$stub => $target], ['protector', 'protector.migrations']);
    }

    protected function scheduleTasks(): void
    {
        foreach (config('protector.cleanup') as $target) {
            $mode = ExecutionMode::from($target['mode']);

            if ($mode->shouldSchedule()) {
                $mode->run($target['invokable'], $target['schedule']);
            }
        }
    }

    protected function mergeProtectorConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/protector/cleanup.php', 'protector.cleanup');
        $this->mergeConfigFrom(__DIR__ . '/../config/protector/client.php', 'protector.client');
        $this->mergeConfigFrom(__DIR__ . '/../config/protector/dump.php', 'protector.dump');
        $this->mergeConfigFrom(__DIR__ . '/../config/protector/server.php', 'protector.server');
    }

    protected function mergeDiskConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/filesystems/local.php', 'filesystems.disks.protector_local');
        $this->mergeConfigFrom(__DIR__ . '/../config/filesystems/storage.php', 'filesystems.disks.protector_storage');
    }

    protected function bindProtector(): void
    {
        // Register the Protector class as a singleton and bind the alias.
        // Scoped to the request lifecycle to ensure a new instance is created for each request (e.g. for Octane).
        $this->app->scoped(Protector::class, Protector::class);
        $this->app->bind('protector', Protector::class);

        $this->app->bind(ProtectorConfigContract::class, ProtectorConfig::class);
        $this->app->bind(ProtectorConfiguratorContract::class, ProtectorConfigurator::class);
    }

    protected function bindHelpers(): void
    {
        $this->app->singleton(CrypterContract::class, SodiumCrypter::class);
        $this->app->singleton(DiskHelperContract::class, DiskHelper::class);
    }

    protected function bindSchemaStateProxies(): void
    {
        $this->app->bind(SchemaStateProxyContract::class, function ($app, array $params): SchemaStateProxyContract {
            /** @var ProtectorConfig $protectorConfig */
            $protectorConfig = $params['protectorConfig'];

            $connection = DB::connection($protectorConfig->getConnectionName());
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
}
