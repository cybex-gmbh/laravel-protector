<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class EmptyDumpDirectoryException
 *
 * Thrown if the dump directory is empty.
 *
 * @package Cybex\Protector\Exceptions
 */
class EmptyDumpDirectoryException extends Exception
{
    public function __construct($message = '', $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message ?: 'There are no dumps in the dump directory', $code, $previous);
    }
}
