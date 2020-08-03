<?php

namespace Cybex\Protector\Exceptions;

use Exception;

/**
 * Class InvalidEnvironmentException
 *
 * Thrown if the environment is set to Production and Production environment was not allowed explicitly.
 *
 * @package Cybex\Protector\Exceptions
 */
class InvalidEnvironmentException extends Exception
{
    //
}
