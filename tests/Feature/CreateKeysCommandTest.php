<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Facades\CrypterFacade;
use Cybex\Protector\Tests\TestCase;

class CreateKeysCommandTest extends TestCase
{
    const SUCCESS_MESSAGE = 'Successfully generated crypto key pair.';
    const SEND_TO_ADMIN = 'Send the public key to server admin so that it can be stored.';
    const ADD_TO_ENV = 'Add the private key to your .env file.';
    const PROTECTOR_PUBLIC_KEY_COMMENT = '# Protector Public Key:';
    const PRIVATE_KEY = 'MOCKED_PRIVATE_KEY';
    const PUBLIC_KEY = 'MOCKED_PUBLIC_KEY_FROM_PRIVATE_KEY';

    /**
     * @test
     */
    public function ensureCorrectOutput()
    {
        CrypterFacade::shouldReceive('createPrivateKey')->andReturn(static::PRIVATE_KEY);
        CrypterFacade::shouldReceive('getPublicKeyFromPrivateKey')->andReturn(static::PUBLIC_KEY);

        $privateKeyName = $this->protector->getPrivateKeyEnvKeyName();

        $this->artisan('protector:keys')
            ->expectsOutput(static::SUCCESS_MESSAGE)
            ->expectsOutput(static::SEND_TO_ADMIN)
            ->expectsOutput(static::ADD_TO_ENV)
            ->expectsOutput(sprintf('%s %s', static::PROTECTOR_PUBLIC_KEY_COMMENT, static::PUBLIC_KEY))
            ->expectsOutput(sprintf('%s=%s', $privateKeyName, static::PRIVATE_KEY))
            ->assertExitCode(0);
    }
}
