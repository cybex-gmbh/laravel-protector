<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
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
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $publicKey = $this->option('publicKey');
        $user = config('auth.providers.users.model')::findOrFail($this->argument('userId'));
        $user->tokens()->whereAbilities('["protector:import"]')->delete();
        $userInformation = sprintf('%s|%s (%s)', $user->id, $user->name, $user->email);

        $this->newLine();

        $this->warn(sprintf('Executing for User %s', $userInformation));

        if (!$user->protector_public_key && !$publicKey) {
            $this->fail('The user doesn\'t have a protector public key and none was specified. Please provide a public key for the user.');
        }

        if ($publicKey) {
            $user->protector_public_key = $publicKey;
            $user->save();

            $this->info(sprintf('Protector public key was set for user %s.', $userInformation));
        }

        $this->newLine();

        $token = $user->createToken('protector', ['protector:import']);

        $this->warn('The quotation marks at the start and end of the token are necessary!');
        $this->info(sprintf('%s="%s"', app('protector')->getAuthTokenKeyName(), $token->plainTextToken));
        $this->info(sprintf('%s=%s', app('protector')->getDumpEndpointUrlKeyName(), route('protectorDumpEndpointRoute')));

        $this->newLine();
    }
}
