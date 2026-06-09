<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedDumpGenerationException
 *
 * Thrown if the generation of the database dump has failed.
 *
 * @package Cybex\Protector\Exceptions
 */
class FailedDumpGenerationException extends Exception
{
    public function __construct(?string $message = null, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message ?? 'Dump could not be created.', $code, $previous);
    }
}
