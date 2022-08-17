<?php

namespace Cybex\Protector;

class DumpMetaData
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    public function __construct(
        protected string $database,
        protected string $connection,
        protected string $gitRevision,
        protected string $gitBranch,
        protected string $gitRevisionDate,
        protected array  $dumpedAtDate,
    ) {}

    public static function getNewMetaDataObject(string $database, string $connectionName): static
    {
        $protector = app('protector');

        return new static(
            $database,
            $connectionName,
            $protector->getGitRevision(),
            $protector->getGitBranch(),
            $protector->getGitHeadDate(),
            $protector->getDate()
        );
    }

    public static function createDataObjectFromMetaData(string $dumpFile): static
    {
        $dumpMetaData = static::getDumpMetaData($dumpFile)['meta'];

        return new static(...$dumpMetaData);
    }

    protected static function getDumpMetaData(string $dumpFile): array
    {
        $desiredMetaLines = [
          'options',
            'meta',
        ];

        $lines = static::tail($dumpFile, count($desiredMetaLines));

        // Response has not enough lines.
        if (count($lines) < count($desiredMetaLines)) {
            return false;
        }

        $data = [];

        foreach ($lines as $line) {
            $matches = [];

            // Check if the structure is correct.
            if (preg_match('/^-- (?<type>[a-z0-9]+):(?<data>.+)$/i', $line, $matches)) {
                // Check if the given type is a desired result for meta-data.

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
     * Returns the last x lines from a file in correct order.
     *
     * @param string $file
     * @param int    $lines
     * @param int    $buffer
     *
     * @return array
     */
    protected static function tail(string $file, int $lines, int $buffer = 1024): array
    {
        $protector = app('protector');

        // Open file-handle.
        $fileHandle = $protector->getDisk()->readStream($file);
        // Jump to last character.
        fseek($fileHandle, 0, SEEK_END);

        $linesToRead = $lines;
        $contents    = '';

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
