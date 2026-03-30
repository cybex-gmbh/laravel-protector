<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProviderContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;

class ProtectorMetadataProvider implements MetadataProviderContract
{
    public function __construct(protected ProtectorConfigContract $protectorConfig)
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
            'maxPacketLength' => $this->protectorConfig->getMaxPacketLength(),
            'connectionName' => $this->protectorConfig->getConnectionName(),
            'databaseName' => $this->protectorConfig->getDatabaseName(),
            'dumpCharsets' => $this->protectorConfig->shouldDumpCharsets(),
            'dumpComments' => $this->protectorConfig->shouldDumpComments(),
            'createDb' => $this->protectorConfig->shouldCreateDb(),
            'dropDb' => $this->protectorConfig->shouldDropDb(),
            'dumpData' => $this->protectorConfig->shouldDumpData(),
            'removeAutoIncrementingState' => $this->protectorConfig->shouldRemoveAutoIncrementingState(),
            'useTablespaces' => $this->protectorConfig->shouldUseTablespaces(),
            'metadataProviders' => $this->protectorConfig->getMetadataProviders(),
        ];
    }
}
