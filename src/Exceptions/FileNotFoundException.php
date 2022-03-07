<?php

namespace Cybex\Protector\Exceptions;

use Exception;

/**
 * Class FileNotFoundException
 *
 * Thrown if a file could not be found.
 *
 * @package Cybex\Protector\Exceptions
 */
class FileNotFoundException extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('The file "%s" was not found.', $path));
    }
}
