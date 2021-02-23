<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File Name
    |--------------------------------------------------------------------------
    |
    | Here you may customize the database dump file name.
    |
    | Parameter order:
    | 1: app url
    | 2: database name
    | 3: connection name
    | 4: year
    | 5: month
    | 6: day
    | 7: hour
    | 8: minute
    |
    */
    'fileName' => '%1$s %4$4d-%5$02d-%6$02d %7$02d-%8$02d.sql',

    /*
    |--------------------------------------------------------------------------
    | Maximum Packet Length
    |--------------------------------------------------------------------------
    |
    | Here you may customize the maximum packet length for the MySQL-Dump.
    |
    */
    'maxPacketLength' => '8M',

    /*
    |--------------------------------------------------------------------------
    | Dump Path
    |--------------------------------------------------------------------------
    |
    | Here you may customize the path for the database dumps.
    |
    */
    'dumpPath' => database_path('dumps'),

    /*
    |--------------------------------------------------------------------------
    | Remote Download Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the remote endpoint for downloading the dump.
    |
    */
    'remoteEndpoint' => [
        'serverUrl'     => '',
        // Htaccess may only be used without Laravel Sanctum or basic auth has to be added to the server URL.
        'htaccessLogin' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dump Endpoint Route
    |--------------------------------------------------------------------------
    |
    | Here you may customize the route for the dump endpoint.
    |
    */
    'dumpEndpointRoute' => '/protector/exportDump',

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may customize middleware that will be applied.
    | By default the auth:sanctum middleware is active and prevents the dump API from being public!
    |
    */
    'routeMiddleware' => [
        'auth:sanctum',
    ],

    /*
    |--------------------------------------------------------------------------
    | Protector DB Token
    |--------------------------------------------------------------------------
    |
    | Here you may customize the .env key for the Protector DB token.
    |
    */
    'protectorDbToken' => env('PROTECTOR_DB_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Protector Crypto Key
    |--------------------------------------------------------------------------
    |
    | Here you may customize the .env key for the Protector Crypto Key.
    |
    */
    'protectorCryptoKey' => env('PROTECTOR_CRYPTO_KEY', ''),
];
