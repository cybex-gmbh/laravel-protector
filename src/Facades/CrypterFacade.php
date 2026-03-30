<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Contracts\CrypterContract;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CrypterContract
 */
class CrypterFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return CrypterContract::class;
    }
}
