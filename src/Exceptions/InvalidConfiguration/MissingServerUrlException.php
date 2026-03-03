<?php

namespace Cybex\Protector\Exceptions\InvalidConfiguration;

use Cybex\Protector\Exceptions\InvalidConfigurationException;

/**
 * Class MissingServerUrlException
 *
 * Thrown if no server URL is configured when trying to retrieve a remote dump.
 *
 * @package Cybex\Protector\Exceptions\InvalidConfiguration
 */
class MissingServerUrlException extends InvalidConfigurationException
{
    public function __construct()
    {
        parent::__construct('Server URL is not set or invalid.');
    }
}
