<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedReadingLocalFileException
 *
 * @package Cybex\Protector\Exceptions
 */
class EmptyFileWrittenException extends Exception
{
    public function __construct(string $path, string $disk, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The file "%s" was found to be empty after writing. Intended disk: %s', $path, $disk), $code, $previous);
    }
}
