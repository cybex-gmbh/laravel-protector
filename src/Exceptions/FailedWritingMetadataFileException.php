<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedWritingMetadataFileException
 *
 * @package Cybex\Protector\Exceptions
 */
class FailedWritingMetadataFileException extends Exception
{
    public function __construct(string $dumpFilePath, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Failed writing metadata file for dump "%s". Deleted leftover files on the storage disk.',
                $dumpFilePath,
            ),
            $code,
            $previous
        );
    }
}


