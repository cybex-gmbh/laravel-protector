<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Classes\Config\ProtectorConfigurator;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin ProtectorConfigurator
 */
class ProtectorConfiguratorFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return ProtectorConfiguratorContract::class;
    }
}
