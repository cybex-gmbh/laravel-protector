<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedShellCommandException
 *
 * Thrown if a shell command couldn't be executed properly.
 *
 * @package Cybex\Protector\Exceptions
 */
class FailedMysqlCommandException extends Exception
{
    public function __construct($message = '', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message ?: 'Shell call to mysql client failed.', $code, $previous);
    }
}
