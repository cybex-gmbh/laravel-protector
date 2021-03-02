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
                {--p|publicKey= : The sodium public key for the user.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a token for a specified user id and optionally sets the public key.';

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
        $publicKey = $this->option('publicKey');
        $user      = config('auth.providers.users.model')::findOrFail($this->argument('userId'));
        $user->tokens()->whereAbilities('["protector:import"]')->delete();

        if (!$user->protector_public_key && !$publicKey) {
            $this->error('The user doesn\'t have a protector public key and none was specified. Please provide a public key for the user.');
            return null;
        }

        if ($publicKey) {
            $user->protector_public_key = $publicKey;
            $user->save();

            $this->info(sprintf('Protector public key %s was saved in the database for user %s.', $publicKey, $user->username));
        }

        $token = $user->createToken('protector', ['protector:import']);

        $this->info(sprintf('Token generated for user %s: %s', $user->username, $token->plainTextToken));
    }
}
