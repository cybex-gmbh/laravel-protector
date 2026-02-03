<?php

namespace Cybex\Protector\Exceptions;

use Cybex\Protector\Contracts\MetadataProvider;
use Exception;
use Throwable;

/**
 * Class InvalidMetadataProviderException
 *
 * Thrown if a configured metadata provider does not implement the required interface.
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
