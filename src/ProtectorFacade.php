<?php

namespace Cybex\Protector;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin Protector
 */
class ProtectorFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'protector';
    }
}
