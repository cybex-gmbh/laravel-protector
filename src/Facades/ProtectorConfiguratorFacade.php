<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\ProtectorConfigurator;
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
