<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use File;

class JsonFileMetadataProvider implements MetadataProvider
{
    protected ?string $jsonFilePath;

    public function __construct()
    {
        $this->jsonFilePath = base_path(config('protector.metadata.jsonFilePath'));
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'jsonFile';
    }

    /**
     * @inheritDoc
     */
    public function shouldAppend(): bool
    {
        return File::exists($this->jsonFilePath);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array|string
    {
        return json_decode(File::get($this->jsonFilePath), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
