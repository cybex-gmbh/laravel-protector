<?php

use Cybex\Protector\Enums\ProtectorEnv;

/**
 *  All config values using {@link ProtectorEnv} can be set via .env keys.
 *  Take a look at the {@link ProtectorEnv} enum for all available .env keys.
 */
/*
|--------------------------------------------------------------------------
| Client Configuration
|--------------------------------------------------------------------------
|
| Here you may configure the request for downloading the dump.
|
*/
return [
    /*
    |--------------------------------------------------------------------------
    | Dump Endpoint URL
    |--------------------------------------------------------------------------
    |
    | This value will be used as the endpoint for retrieving remote dumps.
    | The dump endpoint URL will be given to you by a server admin.
    |
    */
    'dumpEndpointUrl' => ProtectorEnv::DUMP_ENDPOINT_URL->get(),

    /*
    |--------------------------------------------------------------------------
    | Auth Token
    |--------------------------------------------------------------------------
    |
    | This value will be used to authenticate requests for retrieving remote dumps.
    | The auth token will be given to you by a server admin.
    |
    */
    'authToken' => ProtectorEnv::AUTH_TOKEN->get(),

    /*
    |--------------------------------------------------------------------------
    | Private Key
    |--------------------------------------------------------------------------
    |
    | This value will be used to decrypt a remote database dump.
    | The private key is retrieved by running "php artisan protector:keys". The public key should be transmitted to a server admin.
    |
    */
    'privateKey' => ProtectorEnv::PRIVATE_KEY->get(),

    /*
    |--------------------------------------------------------------------------
    | Basic Auth Credentials
    |--------------------------------------------------------------------------
    |
    | Basic Auth may only be used without Laravel Sanctum.
    | It is possible to add the credentials to the dump endpoint URL to use both at the same time. This is not recommended.
    | The value format should be "<username>:<password>".
    |
    */
    'basicAuthCredentials' => ProtectorEnv::BASIC_AUTH->get(),

    /*
    |--------------------------------------------------------------------------
    | Http Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may customize the timeout for HTTP requests for receiving remote dumps.
    | The default is 120 seconds.
    |
    */
    'httpTimeout' => ProtectorEnv::HTTP_TIMEOUT->get(default: 120),
];
