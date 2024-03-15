<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FileNotFoundException
 *
 * Thrown if a file could not be found.
 */
class FileNotFoundException extends Exception
{
    public function __construct($path, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The file "%s" was not found.', $path), $code, $previous);
    }
}
