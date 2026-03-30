<?php

namespace Cybex\Protector\Contracts;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Support\Collection;

interface ProtectorConfigContract
{
    public function getBaseDirectory(): string;

    public function getDisk(?string $diskName = null): Filesystem;

    public function getConnectionConfig(): array|false;

    public function getDatabaseConfig(): array|false;

    public function getDatabaseName(): string;

    public function getConnectionName(): string;

    public function setConnectionName(?string $connectionName): static;

    public function getAuthToken(): string;

    public function getAuthTokenKeyName(): string;

    public function setAuthToken(string $authToken): static;

    public function getDumpEndpointUrl(): string;

    public function getDumpEndpointUrlKeyName(): string;

    public function setDumpEndpointUrl(string $dumpEndpointUrl): static;

    public function getMaxPacketLength(): string;

    public function setMaxPacketLength(string $maxPacketLength): static;

    public function withDefaultMaxPacketLength(): static;

    public function getMetadataProviders(): Collection;

    public function setMetadataProviders(array $metadataProviders): static;

    public function getPrivateKey(): string;

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

    public function withDropDb(): static;

    public function withoutDropDb(): static;

    public function shouldUseTablespaces(): bool;

    public function withTablespaces(): static;

    public function withoutTablespaces(): static;

    public function shouldEncrypt(): bool;

    public function getProxyForSchemaState(): SchemaState;

    public function getSchemaStateParameters(): array;
}
