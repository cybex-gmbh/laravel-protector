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
    public string $httpResponse = 'Configuration error on the server';

    public function __construct(array $functions, $code = 0, Throwable $previous = null)
    {
        $missingFunctions = implode(', ', array_keys($functions, filter_value: false, strict: true));

        parent::__construct(
            sprintf('Required shell commands are disabled on your server, the following functions are not available: %s', $missingFunctions),
            $code,
            $previous
        );
    }
}
