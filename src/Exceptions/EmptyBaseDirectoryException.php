<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class EmptyBaseDirectoryException
 *
 * Thrown if the dump folder is empty.
 *
 * @package Cybex\Protector\Exceptions
 */
class EmptyBaseDirectoryException extends Exception
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message ?: 'There are no dumps in the dump folder', $code, $previous);
    }
}
