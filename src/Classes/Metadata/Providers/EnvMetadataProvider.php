<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;

class EnvMetadataProvider implements MetadataProvider
{
    public const METADATA_KEY = 'env';

    protected ?string $envMetadata;

    public function __construct()
    {
        $this->envMetadata = config('protector.additionalEnvMetadata');
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
    public function getMetadata(): array
    {
        return [
            static::METADATA_KEY => $this->envMetadata
        ];
    }
}
