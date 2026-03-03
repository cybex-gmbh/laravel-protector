<?php

namespace Cybex\Protector\Exceptions\InvalidConfiguration;

use Cybex\Protector\Exceptions\InvalidConfigurationException;

/**
 * Class MissingPrivateKeyException
 *
 * Thrown if no private key is configured while using Laravel Sanctum.
 *
 * @package Cybex\Protector\Exceptions\InvalidConfiguration
 */
class MissingPrivateKeyException extends InvalidConfigurationException
{
    public function __construct()
    {
        parent::__construct('For using the Protector with Laravel Sanctum a private key is required. There was none found.');
    }
}
