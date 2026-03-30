<?php

namespace Cybex\Protector\Contracts;

use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;

interface ProtectorConfigContract
{
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
     * Returns the database config for the given connection.
     */
    public function getDatabaseConfig(): array|false;

    /**
     * Returns the database name specified in the connectionConfig array.
     */
    public function getDatabaseName(): string;

    public function getConnectionName(): string;

    /**
     * @throws InvalidConnectionException
     */
    public function setConnectionName(?string $connectionName): static;

    public function getAuthToken(): string;

    /**
     * Gets the name of the .env key for the auth token.
     */
    public function getAuthTokenKeyName(): string;

    public function setAuthToken(string $authToken): static;

    public function getDumpEndpointUrl(): string;

    /**
     * Gets the name of the .env key for the Protector dump endpoint URL.
     */
    public function getDumpEndpointUrlKeyName(): string;

    public function setDumpEndpointUrl(string $dumpEndpointUrl): static;

    /**
     * Returns the maximum packet length specified in the config.
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function getMaxPacketLength(): string;

    /**
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function setMaxPacketLength(string $maxPacketLength): static;

    /**
     * Uses the max packet length specified in the config file.
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function withDefaultMaxPacketLength(): static;

    /**
     * The metadata provider classes can be configured on the protector instance, else we return the config default.
     *
     * @return Collection
     */
    public function getMetadataProviders(): Collection;

    public function setMetadataProviders(array $metadataProviders): static;

    public function getPrivateKey(): string;

    /**
     * Gets the name of the .env key for the Protector private key.
     */
    public function getPrivateKeyName(): string;

    public function withPrivateKey(string $privateKey): static;

    public function shouldRemoveAutoIncrementingState(): bool;

    public function withAutoIncrementingState(): static;

    public function withoutAutoIncrementingState(): static;

    public function shouldDumpCharsets(): bool;

    public function withCharsets(): static;

    public function withoutCharsets(): static;

    public function shouldDumpComments(): bool;

    public function withComments(): static;

    public function withoutComments(): static;

    public function shouldCreateDb(): bool;

    public function withCreateDb(): static;

    public function withoutCreateDb(): static;

    public function shouldDumpData(): bool;

    public function withData(): static;

    public function withoutData(): static;

    public function shouldDropDb(): bool;

    /**
     * Defines that existing databases should be dropped before importing a dump.
     * Only works if used together with the $createDb option.
     * (PostgreSQL only, controls the --clean flag)
     */
    public function withDropDb(): static;

    /**
     * Defines that existing databases should not be dropped before importing a dump.
     * Only works if used together with the $createDb option
     * (PostgreSQL only, controls the --clean flag)
     */
    public function withoutDropDb(): static;

    public function shouldUseTablespaces(): bool;

    public function withTablespaces(): static;

    public function withoutTablespaces(): static;

    public function shouldEncrypt(): bool;

    /**
     * @throws UnsupportedDatabaseException
     */
    public function getProxyForSchemaState(): SchemaState;

    /**
     * Gets the current schema state parameters.
     * These may change between calls, as the protector could be reconfigured to use a different connection and thus a different schema state proxy.
     *
     * @return array
     */
    public function getSchemaStateParameters(): array;
}
