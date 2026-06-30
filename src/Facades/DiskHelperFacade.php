<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Contracts\DiskHelperContract;
use Illuminate\Support\Facades\Facade;

/**
 * @internal
 * @mixin DiskHelperContract
 */
class DiskHelperFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DiskHelperContract::class;
    }
}

