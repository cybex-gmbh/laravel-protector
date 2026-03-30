<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProviderContract;

class EnvMetadataProvider implements MetadataProviderContract
{
    protected ?string $envMetadata;

    public function __construct()
    {
        $this->envMetadata = config('protector.dump.metadata.envValue');
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'env';
    }

    /**
     * @inheritDoc
     */
    public function shouldAppend(): bool
    {
        return $this->envMetadata ?? false;
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array|string
    {
        return $this->envMetadata;
    }
}
