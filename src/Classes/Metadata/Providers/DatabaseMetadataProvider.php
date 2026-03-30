<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProviderContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;

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
        return [
            'database' => $this->protectorConfig->getDatabaseName(),
            'connection' => $this->protectorConfig->getConnectionName(),
            'dumpedAtDate' => now(),
            'dumpParameters' => $this->protectorConfig->getSchemaStateParameters(),
        ];
    }
}
