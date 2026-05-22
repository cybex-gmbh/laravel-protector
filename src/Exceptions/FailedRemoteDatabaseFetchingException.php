<?php

namespace Cybex\Protector\Exceptions;

use Exception;
use Throwable;

/**
 * Class FailedRemoteDatabaseFetchingException
 *
 * Thrown if the remote database could not be fetched successfully.
 *
 * @package Cybex\Protector\Exceptions
 */
class FailedRemoteDatabaseFetchingException extends Exception
{
    public function __construct(?string $additionalMessage = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Could not fetch database from remote server. %s', $additionalMessage ?? ''),
            $code,
            $previous
        );
    }
}
