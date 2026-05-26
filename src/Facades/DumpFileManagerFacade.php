<?php

namespace Cybex\Protector\Facades;

use Cybex\Protector\Contracts\DumpFileManagerContract;
use Illuminate\Support\Facades\Facade;

/**
 * @internal
 * @mixin DumpFileManagerContract
 */
class DumpFileManagerFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DumpFileManagerContract::class;
    }
}

