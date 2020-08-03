<?php

namespace Cybex\Protector;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cybex\Protector\Skeleton\SkeletonClass
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
