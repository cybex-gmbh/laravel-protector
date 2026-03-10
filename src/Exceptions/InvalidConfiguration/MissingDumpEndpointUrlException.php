<?php

namespace Cybex\Protector\Exceptions\InvalidConfiguration;

use Cybex\Protector\Exceptions\InvalidConfigurationException;

/**
 * Class MissingDumpEndpointUrlException
 *
 * Thrown if no dump endpoint URL is configured when trying to retrieve a remote dump.
 *
 * @package Cybex\Protector\Exceptions\InvalidConfiguration
 */
class MissingDumpEndpointUrlException extends InvalidConfigurationException
{
    public function __construct()
    {
        parent::__construct('Dump endpoint URL is not set or invalid.');
    }
}
