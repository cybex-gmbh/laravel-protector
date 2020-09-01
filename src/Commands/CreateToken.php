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
                {userId : The user id the token is created for.}';

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
        $user  = config('auth.providers.users.model')::findOrFail($this->argument('userId'));
        $user->tokens()->whereAbilities('["protector:import"]')->delete();

        $token = $user->createToken('protector', ['protector:import']);

        $this->info(sprintf('Token generated for user %s: %s', $user->username, $token->plainTextToken));
    }
}
