<?php

namespace Cybex\Protector\Classes\Metadata;

use Cybex\Protector\Contracts\MetadataProvider;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidMetadataProviderException;
use Cybex\Protector\Protector;
use Illuminate\Support\Collection;

class MetadataHandler
{
    public function __construct(protected Protector $protector)
    {
    }

    /**
     * Returns the metadata from all configured providers.
     */
    public function getMetadata(): array
    {
        $metadata = [];

        foreach ($this->getProviders() as $provider) {
            if ($provider->shouldAppend()) {
                $metadata = array_merge($metadata, $provider->getMetadata());
            }
        }

        return $metadata;
    }

    /**
     * Returns the appended metadata from a file.
     */
    public function getDumpMetadata(string $dumpFile): bool|array
    {
        $desiredMetaLines = [
            'options',
            'meta',
        ];

        $lines = $this->tail($dumpFile, count($desiredMetaLines));

        // Response has not enough lines.
        if (count($lines) < count($desiredMetaLines)) {
            return false;
        }

        $data = [];

        foreach ($lines as $line) {
            $matches = [];

            // Check if the structure is correct.
            if (preg_match('/^-- (?<type>[a-z0-9]+):(?<data>.+)$/i', $line, $matches)) {
                // Check if the given type is a desired result for metadata.
                if (in_array($matches['type'], $desiredMetaLines)) {
                    $decodedData = json_decode($matches['data'], true);

                    // We store json-encoded arrays, if we do not get an array back, that means something went wrong.
                    if (!is_array($decodedData)) {
                        return false;
                    }

                    $data[$matches['type']] = $decodedData;
                }
            }
        }

        return $data;
    }

    /**
     * @return Collection<MetadataProvider>
     * @throws InvalidMetadataProviderException
     */
    protected function getProviders(): Collection
    {
        return $this->protector->getMetadataProviders()
            ->map(function ($providerClass) {
                $provider = app()->makeWith($providerClass, [Protector::class => $this->protector]);

                if (!is_a($provider, MetadataProvider::class)) {
                    throw new InvalidMetadataProviderException(invalidProvider: $providerClass);
                }

                return $provider;
            });
    }

    /**
     * Returns the last x lines from a file in correct order.
     *
     * @throws FileNotFoundException
     */
    protected function tail(string $file, int $lines, int $buffer = 1024): array
    {
        // Open file-handle.
        $fileHandle = $this->protector->getDisk()->readStream($file);

        if (!is_resource($fileHandle)) {
            throw new FileNotFoundException($file);
        }

        // Jump to last character.
        fseek($fileHandle, 0, SEEK_END);

        $linesToRead = $lines;
        $contents = '';

        // Only read file as long as file-pointer is not at start of the file and there are still lines to read open.
        while (ftell($fileHandle) && $linesToRead >= 0) {
            // Get the max length for reading, in case the buffer is longer than the remaining file-length.
            $seekLength = min(ftell($fileHandle), $buffer);

            // Set the pointer to a position in front of the current pointer.
            fseek($fileHandle, -$seekLength, SEEK_CUR);

            // Get the next content-chunk by using the according length.
            $contents = ($chunk = fread($fileHandle, $seekLength)) . $contents;

            // Reset pointer to the position before reading the current chunk.
            fseek($fileHandle, -mb_strlen($chunk, 'UTF-8'), SEEK_CUR);

            // Decrease count of lines to read by the amount of new-lines given in the current chunk.
            $linesToRead -= substr_count($chunk, "\n");
        }

        // Get the last x lines from file.
        return array_slice(explode("\n", $contents), -$lines);
    }
}
