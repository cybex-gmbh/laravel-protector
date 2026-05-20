<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Facades\CrypterFacade;
use Cybex\Protector\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CreateKeysCommandTest extends TestCase
{
    protected const string PRIVATE_KEY = 'MOCKED_PRIVATE_KEY';
    protected const string PUBLIC_KEY = 'MOCKED_PUBLIC_KEY_FROM_PRIVATE_KEY';

    #[Test]
    public function ensureCorrectOutput(): void
    {
        CrypterFacade::shouldReceive('createPrivateKey')->andReturn(static::PRIVATE_KEY);
        CrypterFacade::shouldReceive('getPublicKeyFromPrivateKey')->andReturn(static::PUBLIC_KEY);

        $this->artisan('protector:keys')
            ->expectsOutputToContain(static::PUBLIC_KEY)
            ->expectsOutputToContain(static::PRIVATE_KEY)
            ->assertExitCode(0);
    }
}
