<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class ShellAccessDeniedException
 *
 * Thrown when the shell access is denied.
 *
 * @package Cybex\Protector\Exceptions
 */
class ShellAccessDeniedException extends Exception
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message ?: 'Shell commands are disabled on your server, exec() must be enabled.', $code, $previous);
    }
}
