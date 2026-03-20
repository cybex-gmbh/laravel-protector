<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\ProtectorConfig;

class DatabaseMetadataProvider implements MetadataProvider
{
    public function __construct(protected ProtectorConfig $protectorConfig)
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
