<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Protector;
use Cybex\Protector\ProtectorConfig;
use Cybex\Protector\Tests\TestCase;

class ShellAccessDeniedExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = $this->getMockBuilder(Protector::class)
            ->setConstructorArgs([app(ProtectorConfig::class)])
            ->onlyMethods(['checkFunctionExists'])
            ->getMock();
    }

    /**
     * @test
     */
    public function failOnMissingFunction()
    {
        $this->protector->method('checkFunctionExists')
            ->willReturn(false);

        $this->expectException(ShellAccessDeniedException::class);
        $this->protector->guardRequiredFunctionsEnabled();
    }

    /**
     * @test
     */
    public function succeedWhenAllFunctionsAreAvailable()
    {
        $this->protector->method('checkFunctionExists')
            ->willReturn(true);

        $this->protector->guardRequiredFunctionsEnabled();

        $this->expectNotToPerformAssertions();
    }
}
