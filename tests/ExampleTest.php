<?php

namespace Cybex\Protector\Tests;

use Orchestra\Testbench\TestCase;
use Cybex\Protector\ProtectorServiceProvider;

class ExampleTest extends TestCase
{

    protected function getPackageProviders($app)
    {
        return [ProtectorServiceProvider::class];
    }
    
    /** @test */
    public function true_is_true()
    {
        $this->assertTrue(true);
    }
}
