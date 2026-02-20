<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Protector;

class ProtectorMetadataProvider implements MetadataProvider
{
    public function __construct(protected Protector $protector)
    {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'protector';
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
            'maxPacketLength' => $this->protector->getMaxPacketLength(),
            'connectionName' => $this->protector->getConnectionName(),
            'databaseName' => $this->protector->getDatabaseName(),
            'dumpCharsets' => $this->protector->shouldDumpCharsets(),
            'dumpComments' => $this->protector->shouldDumpComments(),
            'createDb' => $this->protector->shouldCreateDb(),
            'dropDb' => $this->protector->shouldDropDb(),
            'dumpData' => $this->protector->shouldDumpData(),
            'removeAutoIncrementingState' => $this->protector->shouldRemoveAutoIncrementingState(),
            'useTablespaces' => $this->protector->shouldUseTablespaces(),
            'metadataProviders' => $this->protector->getMetadataProviders(),
        ];
    }
}
