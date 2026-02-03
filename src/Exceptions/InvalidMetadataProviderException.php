<?php

namespace Cybex\Protector\Exceptions;

use Cybex\Protector\Contracts\MetadataProvider;
use Exception;
use Throwable;

/**
 * Class InvalidEnvironmentException
 *
 * Thrown if the configuration is invalid or missing.
 *
 * @package Cybex\Protector\Exceptions
 */
class InvalidMetadataProviderException extends Exception
{
    public function __construct(string $invalidProvider, int $code = 0, ?Throwable $previous = null)
    {
        $message = sprintf('The configured metadata provider %s does not implement the required interface %s', $invalidProvider, MetadataProvider::class);

        parent::__construct($message, $code, $previous);
    }
}
