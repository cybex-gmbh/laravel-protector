<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Facades\CrypterFacade;
use Cybex\Protector\Tests\TestCase;

class CreateKeysCommandTest extends TestCase
{
    const PRIVATE_KEY = 'MOCKED_PRIVATE_KEY';
    const PUBLIC_KEY = 'MOCKED_PUBLIC_KEY_FROM_PRIVATE_KEY';

    /**
     * @test
     */
    public function ensureCorrectOutput()
    {
        CrypterFacade::shouldReceive('createPrivateKey')->andReturn(static::PRIVATE_KEY);
        CrypterFacade::shouldReceive('getPublicKeyFromPrivateKey')->andReturn(static::PUBLIC_KEY);

        $this->artisan('protector:keys')
            ->expectsOutputToContain(static::PUBLIC_KEY)
            ->expectsOutputToContain(static::PRIVATE_KEY)
            ->assertExitCode(0);
    }
}
