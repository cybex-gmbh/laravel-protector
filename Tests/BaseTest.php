<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\ProtectorServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class BaseTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ProtectorServiceProvider::class,
        ];
    }
}
