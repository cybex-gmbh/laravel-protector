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
    'fileName' => '%s %s %s %4d-%02d-%02d %02d-%02d.sql',

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
   | Remote Endpoint Configuration
   |--------------------------------------------------------------------------
   |
   | Here you may configure the remote endpoint.
   |
   */
    'remoteEndpoint' => [
        'serverUrl'     => '',
        'htaccessLogin' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Dump Endpoint
    |--------------------------------------------------------------------------
    |
    | Here you may customize the remote dump endpoint.
    |
    */
    'remoteDumpEndpoint' => '/protector/exportDump',

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may customize middleware that will be applied.
    | By default the auth:sanctum middleware is active.
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
    'protector_db_token' => env('PROTECTOR_DB_TOKEN')
];
