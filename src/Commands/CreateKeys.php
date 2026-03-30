<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\CrypterContract;
use Illuminate\Console\Command;

/**
 * Class CreateKeys
 * @package Cybex\Protector\Commands;
 */
class CreateKeys extends Command
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

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $crypter = app(CrypterContract::class);
        $privateKey = $crypter->createPrivateKey();
        $publicKey = $crypter->getPublicKeyFromPrivateKey($privateKey);

        $this->newLine();

        $this->info('Successfully generated crypto key pair.');

        $this->newLine();

        $this->comment('Send the public key to server admin so that it can be stored.');
        $this->comment('Add the private key to your .env file.');

        $this->newLine();

        $this->info(sprintf('# Protector Public Key: %s', $publicKey));
        $this->info(sprintf('%s=%s', app('protector')->getConfig()->getPrivateKeyName(), $privateKey));

        $this->newLine();
    }
}
