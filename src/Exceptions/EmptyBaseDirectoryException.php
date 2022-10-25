<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedShellCommandException
 *
 * Thrown if a shell command couldn't be executed properly.
 */
class EmptyBaseDirectoryException extends Exception
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message ?: 'There are no dumps in the dump folder', $code, $previous);
    }
}
