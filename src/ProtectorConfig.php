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
use ReflectionClass;
use ReflectionParameter;

class ProtectorConfig extends AbstractProtectorConfig implements ProtectorConfigContract
{
    protected array $schemaStateParameters;

    /**
     * @throws InvalidConnectionException
     */
    public function __construct(
        ?bool $createDb = null,
        ?bool $dropDb = null,
        ?bool $tablespaces = null,
        ?bool $dumpComments = null,
        ?bool $dumpCharsets = null,
        ?bool $dumpData = null,
        ?bool $removeAutoIncrementingState = null,
        ?string $connectionName = null,
        ?string $dumpEndpointUrl = null,
        ?string $authToken = null,
        ?string $basicAuth = null,
        ?string $privateKey = null,
        ?string $maxPacketLength = null,
        ?int $chunkSize = null,
        ?int $httpTimeout = null,
        ?array $metadataProviders = null
    )
    {
        $this->connectionName = $connectionName ?? config('database.default');

        if ((config(sprintf('database.connections.%s', $this->connectionName), false)) === false) {
            throw new InvalidConnectionException('Invalid database configuration');
        }

        $this->connectionConfig = config(sprintf('database.connections.%s', $this->connectionName), false);

        foreach ($this->getConstructorParameters() as $parameter) {
            if ($$parameter !== null) {
                $this->$parameter = $$parameter;
            }
        }
    }

    /** {@inheritDoc} */
    public function getBaseDirectory(): string
    {
        return $this->getConfigValueForKey('dump.baseDirectory') ?? '';
    }

    /** {@inheritDoc} */
    public function getDisk(): Filesystem
    {
        return Storage::disk($this->getDiskName());
    }

    public function getDiskName(): string
    {
        return $this->getConfigValueForKey('dump.diskName', config('filesystems.default'));
    }

    /** {@inheritDoc} */
    public function getConnectionConfig(): array|false
    {
        return $this->connectionConfig;
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

    public function getAuthToken(): string
    {
        return $this->authToken ?? $this->getConfigValueForKey('client.authToken');
    }

    /** {@inheritDoc} */
    public function getAuthTokenKeyName(): string
    {
        return 'PROTECTOR_CLIENT_AUTH_TOKEN';
    }

    /** {@inheritDoc} */
    public function getBasicAuthCredentials(): ?string
    {
        return $this->basicAuth ?? $this->getConfigValueForKey('client.basicAuthCredentials');
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey ?? $this->getConfigValueForKey('client.privateKey');
    }

    /** {@inheritDoc} */
    public function getPrivateKeyName(): string
    {
        return 'PROTECTOR_CLIENT_PRIVATE_KEY';
    }

    public function getDumpEndpointUrl(): string
    {
        return $this->dumpEndpointUrl ?? $this->getConfigValueForKey('client.dumpEndpointUrl');
    }

    /** {@inheritDoc} */
    public function getDumpEndpointUrlKeyName(): string
    {
        return 'PROTECTOR_CLIENT_DUMP_ENDPOINT_URL';
    }

    /** {@inheritDoc} */
    public function getMaxPacketLength(): string
    {
        return $this->maxPacketLength ?? $this->getConfigValueForKey('dump.maxPacketLength');
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize ?? $this->getConfigValueForKey('server.chunkSize');
    }

    public function getHttpTimeout(): int
    {
        return $this->httpTimeout ?? $this->getConfigValueForKey('client.httpTimeout');
    }

    /** {@inheritDoc} */
    public function getMetadataProviders(): Collection
    {
        $additionalMetadataProviders = collect($this->metadataProviders ?? $this->getConfigValueForKey('dump.metadata.providers'));

        return $additionalMetadataProviders->prepend(DatabaseMetadataProvider::class);
    }

    public function shouldRemoveAutoIncrementingState(): bool
    {
        return $this->removeAutoIncrementingState;
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

    public function shouldDumpData(): bool
    {
        return $this->dumpData;
    }

    public function shouldDropDb(): bool
    {
        return $this->dropDb;
    }

    public function shouldUseTablespaces(): bool
    {
        return $this->tablespaces;
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

    public function getSchemaStateParameters(): array
    {
        if (!isset($this->schemaStateParameters)) {
            $this->getProxyForSchemaState();
        }

        return $this->schemaStateParameters;
    }

    protected function getConstructorParameters(): array
    {
        return array_map(
            fn(ReflectionParameter $parameter) => $parameter->getName(),
            (new ReflectionClass($this))->getConstructor()->getParameters()
        );
    }
}
