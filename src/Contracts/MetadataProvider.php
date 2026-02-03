<?php

namespace Cybex\Protector\Contracts;

interface MetadataProvider
{
    /**
     * Determines whether the metadata should be appended.
     */
    public function shouldAppend(): bool;

    /**
     * Returns the metadata for the provider.
     */
    public function getMetadata(): array;
}
