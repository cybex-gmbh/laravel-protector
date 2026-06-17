<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Enums\ProtectorEnv;
use function Laravel\Prompts\intro;

/**
 * Class CreateKeys
 * @package Cybex\Protector\Commands;
 */
class CreateKeys extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates public and private crypto keys.';

    protected function executeCommand(): int
    {
        $crypter = app(CrypterContract::class);
        $privateKey = $crypter->createPrivateKey();
        $publicKey = $crypter->getPublicKeyFromPrivateKey($privateKey);

        intro('Successfully generated crypto key pair.');

        $this->comment('Send the public key to server admin so that it can be stored.');
        $this->comment('Add the private key to your .env file.');

        $this->newLine();

        $this->info(sprintf('# Protector Public Key: %s', $publicKey));
        $this->info(sprintf('%s=%s', ProtectorEnv::PRIVATE_KEY->key(), $privateKey));

        $this->newLine();

        return self::SUCCESS;
    }
}
