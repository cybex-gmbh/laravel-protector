<?php

namespace Cybex\Protector\Contracts;

use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;

interface ProtectorConfigContract
{
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
    );

    /**
     * Returns the config value for the baseDirectory key.
     */
    public function getBaseDirectory(): string;

    /**
     * Returns the disk which is stated in the config. If no disk is stated, the default filesystem disk will be returned.
     */
    public function getDisk(?string $diskName = null): Filesystem;

    /**
     * Returns the current connection configuration.
     */
    public function getConnectionConfig(): array|false;

    /**
     * Returns the database name specified in the connectionConfig array.
     */
    public function getDatabaseName(): string;

    public function getConnectionName(): string;

    public function getAuthToken(): string;

    /**
     * Gets the name of the .env key for the auth token.
     */
    public function getAuthTokenKeyName(): string;

    /**
     * Basic Auth may only be used without Laravel Sanctum.
     */
    public function getBasicAuthCredentials(): ?string;

    public function getPrivateKey(): string;

    /**
     * Gets the name of the .env key for the Protector private key.
     */
    public function getPrivateKeyName(): string;

    public function getDumpEndpointUrl(): string;

    /**
     * Gets the name of the .env key for the Protector dump endpoint URL.
     */
    public function getDumpEndpointUrlKeyName(): string;

    /**
     * Returns the maximum packet length specified in the config.
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function getMaxPacketLength(): string;

    public function getChunkSize(): int;

    public function getHttpTimeout(): int;

    /**
     * The metadata provider classes can be configured on the protector instance, else we return the config default.
     *
     * @return Collection
     */
    public function getMetadataProviders(): Collection;

    public function shouldRemoveAutoIncrementingState(): bool;

    public function shouldDumpCharsets(): bool;

    public function shouldDumpComments(): bool;

    public function shouldCreateDb(): bool;

    public function shouldDumpData(): bool;

    public function shouldDropDb(): bool;

    public function shouldUseTablespaces(): bool;

    public function shouldEncrypt(): bool;

    /**
     * @throws UnsupportedDatabaseException
     */
    public function getProxyForSchemaState(): SchemaState;

    public function getSchemaStateParameters(): array;
}
