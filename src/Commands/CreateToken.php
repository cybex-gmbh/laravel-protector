<?php

namespace Cybex\Protector\Commands;

use Illuminate\Console\Command;

/**
 * Class CreateToken
 * @package Cybex\Protector\Commands;
 */
class CreateToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:token
                {userId : The user id the token is created for.}
                {--c|cryptoKey= : The crypto key for the user.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a token for a specified user id.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $cryptoKey = $this->option('cryptoKey');
        $user  = config('auth.providers.users.model')::findOrFail($this->argument('userId'));

        if (!$user->crypto_key && !$cryptoKey) {
            $this->error('The user doesn\'t have a crypto key and none was specified. Please provide a crypto key for the user.');
            return null;
        }

        if($cryptoKey) {
            $user->crypto_key = $cryptoKey;
            $user->save();

            $this->info(sprintf('Crypto key %s was saved in the database for user %s.', $cryptoKey, $user->username));
        }

        $token = $user->createToken('protector', ['protector:import']);

        $this->info(sprintf('Token generated for user %s: %s', $user->username, $token->plainTextToken));
    }
}
