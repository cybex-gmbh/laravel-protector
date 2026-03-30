<?php

namespace Cybex\Protector\Contracts;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;

interface ProtectorConfigContract
{
    public function setAuthToken(string $authToken): static;

    public function withAutoIncrementingState(): static;

    public function withoutAutoIncrementingState(): static;

    public function withCharsets(): static;

    public function withoutCharsets(): static;

    public function withComments(): static;

    public function withoutComments(): static;

    public function withData(): static;

    public function withoutData(): static;

    public function setConnectionName(?string $connectionName): static;

    public function withCreateDb(): static;

    public function withoutCreateDb(): static;

    public function withDropDb(): static;

    public function withoutDropDb(): static;

    public function withTablespaces(): static;

    public function withoutTablespaces(): static;

    public function setMaxPacketLength(string $maxPacketLength): static;

    public function withDefaultMaxPacketLength(): static;

    public function setDumpEndpointUrl(string $dumpEndpointUrl): static;

    public function withPrivateKey(string $privateKey): static;

    public function setMetadataProviders(array $metadataProviders): static;

    public function getAuthToken(): string;

    public function getAuthTokenKeyName(): string;

    public function getDatabaseConfig(): mixed;

    public function getDatabaseName(): string;

    public function getMaxPacketLength(): string;

    public function getPrivateKeyName(): string;

    public function getPrivateKey(): string;

    public function getConnectionName(): string;

    public function getConnectionConfig(): mixed;

    public function getDumpEndpointUrl(): string;

    public function getDumpEndpointUrlKeyName(): string;

    public function getMetadataProviders(): Collection;

    public function getBaseDirectory(): string;

    public function getDisk(?string $diskName = null): Filesystem;

    public function shouldEncrypt(): bool;

    public function shouldDumpCharsets(): bool;

    public function shouldDumpComments(): bool;

    public function shouldCreateDb(): bool;

    public function shouldDropDb(): bool;

    public function shouldDumpData(): bool;

    public function shouldRemoveAutoIncrementingState(): bool;

    public function shouldUseTablespaces(): bool;

    public function getSchemaStateParameters(): array;

    public function getProxyForSchemaState(): SchemaState;
}
