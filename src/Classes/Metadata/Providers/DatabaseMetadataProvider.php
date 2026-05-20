<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProviderContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;

class DatabaseMetadataProvider implements MetadataProviderContract
{
    public function __construct(protected ProtectorConfigContract $protectorConfig)
    {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'database';
    }

    /**
     * @inheritDoc
     */
    public function shouldAppend(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array|string
    {
        $connectionName = $this->protectorConfig->getConnectionName();
        $schemaState = app(SchemaStateProxyContract::class, ['protectorConfig' => $this->protectorConfig]);

        return [
            'database' => $this->protectorConfig->getDatabaseName(),
            'connection' => $connectionName,
            'dumpedAtDate' => now(),
            'dumpParameters' => $schemaState->getParameters(),
        ];
    }
}
