<?php

use Cybex\Protector\Enums\ProtectorEnv;


/**
 *  All config values using {@link ProtectorEnv} can be set via .env keys.
 *  Take a look at the {@link ProtectorEnv} enum for all available .env keys.
 */
/*
|--------------------------------------------------------------------------
| Server Configuration
|--------------------------------------------------------------------------
|
| Here you may configure the server.
|
*/
return [
    /*
    |--------------------------------------------------------------------------
    | Dump Endpoint Route
    |--------------------------------------------------------------------------
    |
    | Here you may customize the route for the dump endpoint.
    |
    */
    'dumpEndpointRoute' => ProtectorEnv::DUMP_ENDPOINT_ROUTE->get(default: '/protector/exportDump'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may customize middleware that will be applied.
    | By default, the auth:sanctum middleware is active and prevents the dump API from being public!
    |
    */
    'routeMiddleware' => [
        'auth:sanctum',
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Here you may customize the chunk size used when streaming the database dump from the server.
    | When Laravel Sanctum is active, this chunk size will also apply to the encryption and decryption.
    | By default, the chunk size is set to 20MB.
    |
    */
    'chunkSize' => ProtectorEnv::CHUNK_SIZE->get(default: 20 * 1024 * 1024),
];
