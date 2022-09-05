<?php

namespace Cybex\Protector\Commands;

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
    protected $description = 'Creates Sodium public and private key.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $keyPair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keyPair);

        $this->info('Successfully generated Sodium key pair.');

        $this->info(sprintf('This is your public key: %s', sodium_bin2hex($publicKey)));
        $this->warn('Please send the public key to the admin, so it can be saved in the database!');

        $this->output->newLine();

        $this->info('Please write the key pair in your .env file!');
        $this->warn(sprintf('%s=%s', app('protector')->getPrivateKeyName(), sodium_bin2hex($keyPair)));
    }
}
