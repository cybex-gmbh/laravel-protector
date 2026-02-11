<?php

namespace Cybex\Protector\Contracts;

interface MetadataProvider
{
    /**
     * Identifies the provider and will be used as the key in the final metadata array.
     */
    public function getKey(): string;

    /**
     * Determines whether the metadata should be appended.
     */
    public function shouldAppend(): bool;

    /**
     * Returns the metadata for the provider.
     */
    public function getMetadata(): array|string;
}
