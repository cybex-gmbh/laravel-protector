<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Protector;

class EnvMetadataProvider implements MetadataProvider
{
    public const METADATA_KEY = 'env';

    protected ?string $envMetadata;

    public function __construct(protected Protector $protector)
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
