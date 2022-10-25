<?php

return [
    /*
    |--------------------------------------------------------------------------
    | .env Keys
    |--------------------------------------------------------------------------
    |
    | Use these keys when adding the token, private key and the server url to your .env file.
    |
    | .env key for
    |   - Protector Auth Token: PROTECTOR_AUTH_TOKEN
    |   - Protector Private Key: PROTECTOR_PRIVATE_KEY
    |   - Dump Server URL: PROTECTOR_SERVER_URL
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Name
    |--------------------------------------------------------------------------
    |
    | Here you may customize the database dump file name.
    |
    | Parameter denotation:
    | 1: app url
    | 2: database name
    | 3: connection name
    | 4: year
    | 5: month
    | 6: day
    | 7: hour
    | 8: minute
    | 9: unique id to prevent problems which may occur when multiple users download a dump simultaneously
    */
    'fileName' => '%1$s %4$4d-%5$02d-%6$02d %7$02d-%8$02d %9$s.sql',

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
    | Disk Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may customize the base directory and the disk.
    | By default the default filesystem disk stated in your filesystems-config will be used.
    |
    */
    'baseDirectory' => 'protector',
    // 'diskName' => 'protector',

    /*
    |--------------------------------------------------------------------------
    | Remote Download Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the remote endpoint for downloading the dump.
    |
    */
    'remoteEndpoint' => [
        'serverUrl' => 'protector.invalid/protector/exportDump',
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
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Here you may customize the chunk size used when streaming the database dump from the server.
    | When Laravel Sanctum is active, this chunk size will also apply to the encryption and decryption.
    | By default the chunk size is set to 20MB.
    |
    */
    'chunkSize' => 20 * 1024 * 1024,
];
