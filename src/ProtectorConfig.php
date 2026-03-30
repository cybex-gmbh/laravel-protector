<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\Providers\DatabaseMetadataProvider;
use Cybex\Protector\Classes\SchemaState\MySql\MySqlSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\Postgres\PostgresSchemaStateProxy;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\MySqlSchemaState;
use Illuminate\Database\Schema\PostgresSchemaState;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProtectorConfig implements ProtectorConfigContract
{
    /**
     * Cache for the current connection-name.
     */
    protected string $connectionName;

    /**
     * Cache for the current connection-configuration.
     */
    protected array|false $connectionConfig;

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

    protected string $dumpEndpointUrl = '';

    protected string $authToken = '';

    protected string $basicAuth = '';

    protected string $privateKey = '';

    protected string $maxPacketLength;

    protected int $chunkSize;

    protected int $httpTimeout;

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

    protected array $metadataProviders;

    protected array $schemaStateParameters;

    public function __construct(?string $connectionName = null)
    {
        $this->setConnectionName($connectionName)
            ->withoutCreateDb()
            ->withoutTablespaces();
    }

    /** {@inheritDoc} */
    public function getBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.baseDirectory') ?? '';
    }

    /** {@inheritDoc} */
    public function getDisk(?string $diskName = null): Filesystem
    {
        $diskName ??= $this->getConfigValueForKey('dump.diskName', config('filesystems.default'));

        return Storage::disk($diskName);
    }

    /** {@inheritDoc} */
    public function getConnectionConfig(): array|false
    {
        return $this->connectionConfig;
    }

    /** {@inheritDoc} */
    public function getDatabaseConfig(): array|false
    {
        return config(sprintf('database.connections.%s', $this->connectionName), false);
    }

    /** {@inheritDoc} */
    public function getDatabaseName(): string
    {
        return $this->connectionConfig['database'];
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /** {@inheritDoc} */
    public function setConnectionName(?string $connectionName = null): static
    {
        $this->connectionName = $connectionName ?? config('database.default');

        if (($this->connectionConfig = $this->getDatabaseConfig()) === false) {
            throw new InvalidConnectionException('Invalid database configuration');
        }

        return $this;
    }

    public function getAuthToken(): string
    {
        return $this->authToken ?: $this->getConfigValueForKey('client.authToken');
    }

    /** {@inheritDoc} */
    public function getAuthTokenKeyName(): string
    {
        return 'PROTECTOR_CLIENT_AUTH_TOKEN';
    }

    public function setAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    public function withDefaultAuthToken(): static
    {
        return $this->setAuthToken($this->getConfigValueForKey('client.authToken'));
    }

    /** {@inheritDoc} */
    public function getBasicAuthCredentials(): ?string
    {
        return $this->basicAuth ?: $this->getConfigValueForKey('client.basicAuthCredentials');
    }

    /** {@inheritDoc} */
    public function setBasicAuthCredentials(string $credentials): static
    {
        $this->basicAuth = $credentials;

        return $this;
    }

    /** {@inheritDoc} */
    public function withDefaultBasicAuthCredentials(): static
    {
        return $this->setBasicAuthCredentials($this->getConfigValueForKey('client.basicAuthCredentials'));
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey ?: $this->getConfigValueForKey('client.privateKey');
    }

    /** {@inheritDoc} */
    public function getPrivateKeyName(): string
    {
        return 'PROTECTOR_CLIENT_PRIVATE_KEY';
    }

    public function setPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    public function withDefaultPrivateKey(): static
    {
        return $this->setPrivateKey($this->getConfigValueForKey('client.privateKey'));
    }

    public function getDumpEndpointUrl(): string
    {
        return $this->dumpEndpointUrl ?: $this->getConfigValueForKey('client.dumpEndpointUrl');
    }

    /** {@inheritDoc} */
    public function getDumpEndpointUrlKeyName(): string
    {
        return 'PROTECTOR_CLIENT_DUMP_ENDPOINT_URL';
    }

    public function setDumpEndpointUrl(string $dumpEndpointUrl): static
    {
        $this->dumpEndpointUrl = $dumpEndpointUrl;

        return $this;
    }

    public function withDefaultDumpEndpointUrl(): static
    {
        return $this->setDumpEndpointUrl($this->getConfigValueForKey('client.dumpEndpointUrl'));
    }

    /** {@inheritDoc} */
    public function getMaxPacketLength(): string
    {
        return $this->maxPacketLength ?? $this->getConfigValueForKey('dump.maxPacketLength');
    }

    /** {@inheritDoc} */
    public function setMaxPacketLength(string $maxPacketLength): static
    {
        $this->maxPacketLength = $maxPacketLength;

        return $this;
    }

    /** {@inheritDoc} */
    public function withDefaultMaxPacketLength(): static
    {
        return $this->setMaxPacketLength($this->getConfigValueForKey('dump.maxPacketLength'));
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize ?? $this->getConfigValueForKey('server.chunkSize');
    }

    public function setChunkSize(int $chunkSize): static
    {
        $this->chunkSize = $chunkSize;

        return $this;
    }

    public function withDefaultChunkSize(): static
    {
        return $this->setChunkSize($this->getConfigValueForKey('server.chunkSize'));
    }

    public function getHttpTimeout(): int
    {
        return $this->httpTimeout ?? $this->getConfigValueForKey('client.httpTimeout');
    }

    public function setHttpTimeout(int $httpTimeout): static
    {
        $this->httpTimeout = $httpTimeout;

        return $this;
    }

    public function withDefaultHttpTimeout(): static
    {
        return $this->setHttpTimeout($this->getConfigValueForKey('client.httpTimeout'));
    }

    /** {@inheritDoc} */
    public function getMetadataProviders(): Collection
    {
        $additionalMetadataProviders = collect($this->metadataProviders ?? $this->getConfigValueForKey('dump.metadata.providers'));

        return $additionalMetadataProviders->prepend(DatabaseMetadataProvider::class);
    }

    public function setMetadataProviders(array $metadataProviders): static
    {
        $this->metadataProviders = $metadataProviders;

        return $this;
    }

    public function withDefaultMetadataProviders(): static
    {
        return $this->setMetadataProviders($this->getConfigValueForKey('dump.metadata.providers'));
    }

    public function shouldRemoveAutoIncrementingState(): bool
    {
        return $this->removeAutoIncrementingState;
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

    public function shouldDumpCharsets(): bool
    {
        return $this->dumpCharsets;
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

    public function shouldDumpComments(): bool
    {
        return $this->dumpComments;
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

    public function shouldCreateDb(): bool
    {
        return $this->createDb;
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

    public function shouldDumpData(): bool
    {
        return $this->dumpData;
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

    public function shouldDropDb(): bool
    {
        return $this->dropDb;
    }

    /** {@inheritDoc} */
    public function withDropDb(): static
    {
        $this->dropDb = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function withoutDropDb(): static
    {
        $this->dropDb = false;

        return $this;
    }

    public function shouldUseTablespaces(): bool
    {
        return $this->tablespaces;
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

    public function shouldEncrypt(): bool
    {
        return in_array('auth:sanctum', $this->getConfigValueForKey('server.routeMiddleware', []));
    }

    /** {@inheritDoc} */
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

    /** {@inheritDoc} */
    public function getSchemaStateParameters(): array
    {
        $this->getProxyForSchemaState();

        return $this->schemaStateParameters;
    }

    /**
     * Returns a config value for a specific key and checks for Callables.
     */
    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }
}
