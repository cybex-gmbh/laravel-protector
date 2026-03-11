<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
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
        $keyPair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keyPair);

        $this->newLine();

        $this->info('Successfully generated crypto key pair.');

        $this->newLine();

        $this->comment('Send the public key to server admin so that it can be stored.');
        $this->comment('Add the private key to your .env file.');

        $this->newLine();

        $this->info(sprintf('# Protector Public Key: %s', sodium_bin2hex($publicKey)));
        $this->info(sprintf('%s=%s', app('protector')->getPrivateKeyName(), sodium_bin2hex($keyPair)));

        $this->newLine();
    }
}
