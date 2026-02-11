<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;

class EnvMetadataProvider implements MetadataProvider
{
    protected ?string $envMetadata;

    public function __construct()
    {
        $this->envMetadata = config('protector.additionalEnvMetadata');
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
