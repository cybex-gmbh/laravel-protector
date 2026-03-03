<?php

namespace Cybex\Protector\Exceptions\InvalidConfiguration;

use Cybex\Protector\Exceptions\InvalidConfigurationException;

/**
 * Class NoAuthConfiguredException
 *
 * Thrown if no authentication method is configured.
 *
 * @package Cybex\Protector\Exceptions\InvalidConfiguration
 */
class NoAuthConfiguredException extends InvalidConfigurationException
{
    public function __construct()
    {
        parent::__construct('Either Laravel Sanctum has to be active or basic auth credentials have to be configured.');
    }
}
