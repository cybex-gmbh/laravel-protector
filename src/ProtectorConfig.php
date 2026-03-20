<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\Providers\DatabaseMetadataProvider;
use Cybex\Protector\Classes\SchemaState\MySql\MySqlSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\Postgres\PostgresSchemaStateProxy;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\MySqlSchemaState;
use Illuminate\Database\Schema\PostgresSchemaState;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProtectorConfig
{
    /**
     * Cache for the current connection-name.
     */
    protected string $connectionName;

    /**
     * Cache for the current connection-configuration.
     */
    protected mixed $connectionConfig;

    protected array $schemaStateParameters;

    /**
     * Defines whether dumps should include a DB creation statement.
     */
    protected bool $createDb = true;

    /**
     * Defines whether existing databases should be dropped before importing a dump.
     * Only works if used together with the $createDb option.
     * (PostgreSQL only, controls the --clean flag)
     */
    protected bool $dropDb = true;

    /**
     * The dump endpoint URL.
     */
    protected string $dumpEndpointUrl = '';

    /**
     * The Protector Auth Token.
     */
    protected string $authToken = '';

    /**
     * The Protector Private Key.
     */
    protected string $privateKey = '';

    /**
     * The maximum packet length for the dump.
     */
    protected string $maxPacketLength;

    /**
     * If set to false, the --no-tablespaces dump option will be used.
     */
    protected bool $tablespaces = true;

    /**
     * Specifies whether comments should be added to the dump file.
     */
    protected bool $dumpComments = true;

    /**
     * If false, no SET NAMES statements will be written to the dump.
     */
    protected bool $dumpCharsets = true;

    /**
     * Specifies whether table data should be dumped.
     */
    protected bool $dumpData = true;

    /**
     * If true, the auto-increment state will be stripped from the dump.
     */
    protected bool $removeAutoIncrementingState = false;

    /**
     * The metadata provider classes for the dump metadata.
     */
    protected array $metadataProviders;

    public function __construct(?string $connectionName = null)
    {
        $this->withConnectionName($connectionName)
            ->withDefaultMaxPacketLength()
            ->withoutCreateDb()
            ->withoutTablespaces();
    }

    /**
     * Sets the auth token for Laravel Sanctum authentication.
     */
    public function withAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    public function withAutoIncrementingState(): static
    {
        $this->removeAutoIncrementingState = false;

        return $this;
    }

    public function withoutAutoIncrementingState(): static
    {
        $this->removeAutoIncrementingState = true;

        return $this;
    }

    public function withCharsets(): static
    {
        $this->dumpCharsets = true;

        return $this;
    }

    public function withoutCharsets(): static
    {
        $this->dumpCharsets = false;

        return $this;
    }

    public function withComments(): static
    {
        $this->dumpComments = true;

        return $this;
    }

    public function withoutComments(): static
    {
        $this->dumpComments = false;

        return $this;
    }

    public function withData(): static
    {
        $this->dumpData = true;

        return $this;
    }

    public function withoutData(): static
    {
        $this->dumpData = false;

        return $this;
    }

    /**
     * @throws InvalidConnectionException
     */
    public function withConnectionName(?string $connectionName = null): static
    {
        $this->connectionName = $connectionName ?? config('database.default');

        if (($this->connectionConfig = $this->getDatabaseConfig()) === false) {
            throw new InvalidConnectionException('Invalid database configuration');
        }

        return $this;
    }

    public function withCreateDb(): static
    {
        $this->createDb = true;

        return $this;
    }

    public function withoutCreateDb(): static
    {
        $this->createDb = false;

        return $this;
    }

    /**
     * Defines that existing databases should be dropped before importing a dump.
     * Only works if used together with the $createDb option.
     * (PostgreSQL only, controls the --clean flag)
     */
    public function withDropDb(): static
    {
        $this->dropDb = true;

        return $this;
    }

    /**
     * Defines that existing databases should not be dropped before importing a dump.
     * Only works if used together with the $createDb option
     * (PostgreSQL only, controls the --clean flag)
     */
    public function withoutDropDb(): static
    {
        $this->dropDb = false;

        return $this;
    }

    public function withTablespaces(): static
    {
        $this->tablespaces = true;

        return $this;
    }

    public function withoutTablespaces(): static
    {
        $this->tablespaces = false;

        return $this;
    }

    public function withMaxPacketLength(string $maxPacketLength): static
    {
        $this->maxPacketLength = $maxPacketLength;

        return $this;
    }

    public function withDefaultMaxPacketLength(): static
    {
        $this->maxPacketLength = config('protector.dump.maxPacketLength');

        return $this;
    }

    /**
     * Sets the dump endpoint URL.
     */
    public function withDumpEndpointUrl(string $dumpEndpointUrl): static
    {
        $this->dumpEndpointUrl = $dumpEndpointUrl;

        return $this;
    }

    public function withPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    public function withMetadataProviders(array $metadataProviders): static
    {
        $this->metadataProviders = $metadataProviders;

        return $this;
    }

    /**
     * Returns a config value for a specific key and checks for Callables.
     */
    public function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Retrieves the auth token for Laravel Sanctum authentication.
     */
    public function getAuthToken(): string
    {
        return $this->authToken ?: $this->getConfigValueForKey('client.authToken');
    }

    /**
     * Gets the name of the .env key for the auth token.
     */
    public function getAuthTokenKeyName(): string
    {
        return 'PROTECTOR_CLIENT_AUTH_TOKEN';
    }

    /**
     * Returns the database config for the given connection.
     */
    public function getDatabaseConfig(): mixed
    {
        return config(sprintf('database.connections.%s', $this->connectionName), false);
    }

    /**
     * Returns the database name specified in the connectionConfig array.
     */
    public function getDatabaseName(): string
    {
        return $this->connectionConfig['database'];
    }

    /**
     * Returns the maximum packet length specified in the config.
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function getMaxPacketLength(): string
    {
        return $this->maxPacketLength;
    }

    /**
     * Gets the name of the .env key for the Protector private key.
     */
    public function getPrivateKeyName(): string
    {
        return 'PROTECTOR_CLIENT_PRIVATE_KEY';
    }

    /**
     * Retrieves the private key for encryption.
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey ?: $this->getConfigValueForKey('client.privateKey');
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Returns the current connection configuration.
     */
    public function getConnectionConfig(): mixed
    {
        return $this->connectionConfig;
    }

    /**
     * Retrieves the URL of the dump endpoint.
     */
    public function getDumpEndpointUrl(): string
    {
        return $this->dumpEndpointUrl ?: $this->getConfigValueForKey('client.dumpEndpointUrl');
    }

    /**
     * Gets the name of the .env key for the Protector dump endpoint URL.
     */
    public function getDumpEndpointUrlKeyName(): string
    {
        return 'PROTECTOR_CLIENT_DUMP_ENDPOINT_URL';
    }

    /**
     * The metadata provider classes can be configured on the protector instance, else we return the config default.
     * @return Collection
     */
    public function getMetadataProviders(): Collection
    {
        $additionalMetadataProviders = collect($this->metadataProviders ?? $this->getConfigValueForKey('dump.metadata.providers'));

        return $additionalMetadataProviders->prepend(DatabaseMetadataProvider::class);
    }

    /**
     * Returns the config value for the baseDirectory key.
     */
    public function getBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.baseDirectory') ?? '';
    }

    /**
     * Returns the disk which is stated in the config. If no disk is stated the default filesystem disk will be returned.
     */
    public function getDisk(?string $diskName = null): Filesystem
    {
        $diskName ??= $this->getConfigValueForKey('dump.diskName', config('filesystems.default'));

        return Storage::disk($diskName);
    }

    public function shouldEncrypt(): bool
    {
        return in_array('auth:sanctum', $this->getConfigValueForKey('server.routeMiddleware', []));
    }

    public function shouldDumpCharsets(): bool
    {
        return $this->dumpCharsets;
    }

    public function shouldDumpComments(): bool
    {
        return $this->dumpComments;
    }

    public function shouldCreateDb(): bool
    {
        return $this->createDb;
    }

    public function shouldDropDb(): bool
    {
        return $this->dropDb;
    }

    public function shouldDumpData(): bool
    {
        return $this->dumpData;
    }

    public function shouldRemoveAutoIncrementingState(): bool
    {
        return $this->removeAutoIncrementingState;
    }

    public function shouldUseTablespaces(): bool
    {
        return $this->tablespaces;
    }

    /**
     * Gets the current schema state parameters.
     * These may change between calls, as the protector could be reconfigured to use a different connection and thus a different schema state proxy.
     *
     * @return array
     */
    public function getSchemaStateParameters(): array
    {
        $this->getProxyForSchemaState();

        return $this->schemaStateParameters;
    }

    /**
     * @throws UnsupportedDatabaseException
     */
    public function getProxyForSchemaState(): SchemaState
    {
        $connection = DB::connection($this->getConnectionName());
        $schemaState = $connection->getSchemaState();

        $schemaStateProxy = match (get_class($schemaState)) {
            MySqlSchemaState::class => app(MySqlSchemaStateProxy::class, [$schemaState, $this]),
            PostgresSchemaState::class => app(PostgresSchemaStateProxy::class, [$schemaState, $this]),
            //            SqliteSchemaState::class => app('SqliteSchemaStateProxy', [$schemaState, $this]),
            default => throw new UnsupportedDatabaseException('Unsupported database schema state: ' . class_basename($schemaState)),
        };

        $this->schemaStateParameters = $schemaStateProxy->getParameters();

        return $schemaStateProxy;
    }
}
