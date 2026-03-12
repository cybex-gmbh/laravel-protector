<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Contracts\Crypter;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin Crypter
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
        return Crypter::class;
    }
}
