<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;

class ShellAccessDeniedExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Test]
    public function failOnMissingFunction(): void
    {
        $mock = $this->getCheckFunctionExistsMock(returnValue: false);

        $this->expectException(ShellAccessDeniedException::class);
        $mock->guardRequiredFunctionsEnabled();
    }

    #[Test]
    public function succeedWhenAllFunctionsAreAvailable(): void
    {
        $mock = $this->getCheckFunctionExistsMock(returnValue: true);

        $mock->guardRequiredFunctionsEnabled();
        $this->expectNotToPerformAssertions();
    }

    protected function getCheckFunctionExistsMock(bool $returnValue): Protector|MockInterface
    {
        return $this->partialMock(
            Protector::class,
            fn(MockInterface $mock) => $mock
                ->shouldAllowMockingProtectedMethods()
                ->shouldReceive('checkFunctionExists')
                ->andReturn($returnValue)
        );
    }
}
