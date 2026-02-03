<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Protector;

class DefaultMetadataProvider implements MetadataProvider
{
    public const METADATA_KEY = 'default';

    public function __construct(protected Protector $protector)
    {
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
    public function getMetadata(): array
    {
        return [
            static::METADATA_KEY => [
                'database' => $this->protector->getDatabaseName(),
                'connection' => $this->protector->getConnectionName(),
                'maxPacketLength' => $this->protector->getMaxPacketLength(),
                'dumpedAtDate' => now(),
            ]
        ];
    }
}
