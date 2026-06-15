<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class EmptyFileWrittenException
 *
 * @package Cybex\Protector\Exceptions
 */
class FileNameMayNotContainDirectoryException extends Exception
{
    public function __construct(string $fileName, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Directories are not allowed when passing a file name. Requested file name: %s', $fileName), $code, $previous);
    }
}
