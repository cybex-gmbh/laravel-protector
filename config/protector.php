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
    | 1: database name
    | 2: connection name
    | 3: year
    | 4: month
    | 5: day
    | 6: hour
    | 7: minute
    |
    */
    'fileName' => '%s %s %4d-%02d-%02d %02d-%02d.sql',

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
    'dumpPath' => database_path('/dumps/'),

    /*
   |--------------------------------------------------------------------------
   | Remote Endpoint Configuration
   |--------------------------------------------------------------------------
   |
   | Here you may configure the remote endpoint.
   |
   */
    'remoteEndpoint' => [
        'serverUrl' => '',
        'htaccessLogin' => '',
    ],
];
