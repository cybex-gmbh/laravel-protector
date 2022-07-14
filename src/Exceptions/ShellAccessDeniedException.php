<?php

namespace Cybex\Protector\Exceptions;

use Exception;

/**
 * Class ShellAccessDeniedException
 *
 * Thrown when the shell access is denied.
 *
 * @package Cybex\Protector\Exceptions
 */
class ShellAccessDeniedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Shell commands are disabled on your server, exec() must be enabled.');
    }
}
