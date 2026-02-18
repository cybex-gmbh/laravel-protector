<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Protector;

class DatabaseMetadataProvider implements MetadataProvider
{
    public function __construct(protected Protector $protector)
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
            'database' => $this->protector->getDatabaseName(),
            'connection' => $this->protector->getConnectionName(),
            'dumpedAtDate' => now(),
            'dumpParameters' => $this->protector->getSchemaStateParameters(),
        ];
    }
}
