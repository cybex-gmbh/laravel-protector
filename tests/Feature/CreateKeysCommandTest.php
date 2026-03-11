<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Tests\TestCase;

class CreateKeysCommandTest extends TestCase
{
    const SUCCESS_MESSAGE = 'Successfully generated crypto key pair.';
    const SEND_TO_ADMIN = 'Send the public key to server admin so that it can be stored.';
    const ADD_TO_ENV = 'Add the private key to your .env file.';
    const PROTECTOR_PUBLIC_KEY_COMMENT = '# Protector Public Key: ';

    /**
     * @test
     */
    public function ensureCorrectOutput()
    {
        $privateKeyName = $this->protector->getPrivateKeyName();

        $this->artisan('protector:keys')
            ->expectsOutput(static::SUCCESS_MESSAGE)
            ->expectsOutput(static::SEND_TO_ADMIN)
            ->expectsOutput(static::ADD_TO_ENV)
            ->expectsOutputToContain(static::PROTECTOR_PUBLIC_KEY_COMMENT)
            ->expectsOutputToContain(sprintf('%s=', $privateKeyName))
            ->assertExitCode(0);
    }
}
