<?php

namespace Cybex\Protector\Exceptions\InvalidConfiguration;

use Cybex\Protector\Exceptions\InvalidConfigurationException;

/**
 * Class SanctumBasicAuthConflictException
 *
 * Thrown if the Laravel Sanctum middleware is applied and basic auth is configured.
 *
 * @package Cybex\Protector\Exceptions\InvalidConfiguration
 */
class SanctumBasicAuthConflictException extends InvalidConfigurationException
{
    public function __construct()
    {
        parent::__construct('It is not possible to use basic auth and Laravel Sanctum at the same time, since they use the same header.');
    }
}
