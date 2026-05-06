<?php

namespace Cybex\Protector\Contracts;

use Cybex\Protector\Protector;

interface ProtectorConfiguratorContract
{
    public function createProtector(): Protector;

    public function setConnectionName(string $connectionName): static;

    public function setAuthToken(string $authToken): static;

    /**
     * Basic Auth may only be used without Laravel Sanctum.
     * The value format should be "<username>:<password>".
     */
    public function setBasicAuthCredentials(string $credentials): static;

    public function setPrivateKey(string $privateKey): static;

    public function setDumpEndpointUrl(string $dumpEndpointUrl): static;

    /**
     * The option 'max_allowed_packet' sets an upper limit on the size of any single message between the MySQL server and clients.
     * This has to be set up on the client (here) and the MySQL server.
     * This is not applicable to PostgreSQL.
     */
    public function setMaxPacketLength(string $maxPacketLength): static;

    public function setChunkSize(int $chunkSize): static;

    public function setHttpTimeout(int $httpTimeout): static;

    public function setMetadataProviders(array $metadataProviders): static;

    public function withoutAutoIncrementingState(): static;

    public function withoutCharsets(): static;

    public function withoutComments(): static;

    public function withCreateDb(): static;

    public function withoutData(): static;

    /**
     * Defines that existing databases should not be dropped before importing a dump.
     * Only works if used together with the $createDb option.
     * (PostgreSQL only, controls the --clean flag)
     */
    public function withoutDropDb(): static;

    public function withTablespaces(): static;
}
