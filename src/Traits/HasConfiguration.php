<?php

namespace Cybex\Protector\Traits;

use Cybex\Protector\Exceptions\InvalidConnectionException;

trait HasConfiguration
{
    /**
     * Cache for the current connection-name.
     */
    protected string $connectionName;

    /**
     * Cache for the current connection-configuration.
     */
    protected mixed $connectionConfig;

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
     * The name of the .env key for the Protector DB Token.
     */
    protected string $authTokenKeyName = 'PROTECTOR_AUTH_TOKEN';

    /**
     * The name of the .env key for the Protector Private Key.
     */
    protected string $privateKeyName = 'PROTECTOR_PRIVATE_KEY';

    /**
     * The server url for the dump endpoint.
     */
    protected string $serverUrl = '';

    /**
     * The Protector Auth Token.
     */
    protected string $authToken = '';

    /**
     * The maximum packet length for the dump.
     */
    protected string $maxPacketLength;

    /**
     * If set to false, the --no-tablespaces dump option will be used in MySQL.
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
     * If true, the auto increment state will be stripped from the dump.
     */
    protected bool $removeAutoIncrementingState = false;

    /**
     * Sets the auth token for Laravel Sanctum authentication.
     */
    public function withAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    /**
     * Sets the name of the .env key for the Protector DB Token.
     */
    public function withAuthTokenKeyName(string $authTokenKeyName): static
    {
        $this->authTokenKeyName = $authTokenKeyName;

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
        $this->maxPacketLength = config('protector.maxPacketLength');

        return $this;
    }

    /**
     * Sets the name of the .env key for the Protector Crypto Key.
     */
    public function withPrivateKeyName(string $privateKeyName): static
    {
        $this->privateKeyName = $privateKeyName;

        return $this;
    }

    /**
     * Sets the server url of the dump endpoint.
     */
    public function withServerUrl(string $serverUrl): static
    {
        $this->serverUrl = $serverUrl;

        return $this;
    }

    /**
     * Retrieves the auth token for Laravel Sanctum authentication.
     */
    protected function getAuthToken(): string
    {
        return $this->authToken ?: env($this->authTokenKeyName, '');
    }

    /**
     * Gets the name of the .env key for the Protector DB Token.
     */
    public function getAuthTokenKeyName(): string
    {
        return $this->authTokenKeyName;
    }

    /**
     * Returns the database config for the given connection.
     */
    protected function getDatabaseConfig(): mixed
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

    public function getMaxPacketLength(): string
    {
        return $this->maxPacketLength;
    }

    /**
     * Sets the name of the .env key for the Protector Crypto Key.
     */
    public function getPrivateKeyName(): string
    {
        return $this->privateKeyName;
    }

    /**
     * Retrieves the private key for Sodium encryption.
     */
    protected function getPrivateKey(): string
    {
        return env($this->privateKeyName, '');
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Retrieves the server url of the dump endpoint.
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl ?: $this->getConfigValueForKey('remoteEndpoint.serverUrl');
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
}
