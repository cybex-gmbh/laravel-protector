<?php

return [
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
    | 9: seconds
    */
    'fileName' => '%1$s %4$4d-%5$02d-%6$02d %7$02d_%8$02d_%9$02d.sql',

    /*
    |--------------------------------------------------------------------------
    | Maximum Packet Length
    |--------------------------------------------------------------------------
    |
    | Here you may customize the maximum packet length for the database dump.
    |
    */
    'maxPacketLength' => env('PROTECTOR_MAX_PACKET_LENGTH', '8M'),

    /*
    |--------------------------------------------------------------------------
    | Disk Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may customize the base directory and the disk.
    | By default, the default filesystem disk stated in your filesystems-config will be used.
    |
    */
    'baseDirectory' => env('PROTECTOR_BASE_DIRECTORY', 'protector'),
    // 'diskName' => env('PROTECTOR_DISK_NAME', 'protector'),

    /*
    |--------------------------------------------------------------------------
    | Remote Dump Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the request for downloading the dump.
    |
    */
    'remoteDump' => [
        /*
        |--------------------------------------------------------------------------
        | Server URL
        |--------------------------------------------------------------------------
        |
        | This .env value will be used as the endpoint for retrieving remote dumps.
        | The server URL will be given to you by a server admin.
        | The .env key name should not be changed.
        |
        */
        'serverUrl' => env('PROTECTOR_SERVER_URL'),

        /*
        |--------------------------------------------------------------------------
        | Auth Token
        |--------------------------------------------------------------------------
        |
        | This .env value will be used to authenticate requests for retrieving remote dumps.
        | The auth token will be given to you by a server admin.
        | The .env key name should not be changed.
        |
        */
        'authToken' => env('PROTECTOR_AUTH_TOKEN'),

        /*
        |--------------------------------------------------------------------------
        | Private Key
        |--------------------------------------------------------------------------
        |
        | This .env value will be used to decrypt a remote database dump.
        | The private key is retrieved by running "php artisan protector:keys". The public key should be transmitted to a server admin.
        | The .env key name should not be changed.
        |
        */
        'privateKey' => env('PROTECTOR_PRIVATE_KEY'),

        /*
        |--------------------------------------------------------------------------
        | Basic Auth Credentials
        |--------------------------------------------------------------------------
        |
        | Basic Auth may only be used without Laravel Sanctum.
        | It is possible to add the credentials to the server URL to use both at the same time. This is not recommended.
        | The .env value format should be "<username>:<password>".
        |
        */
        'basicAuthCredentials' => env('PROTECTOR_BASIC_AUTH_CREDENTIALS'),

        /*
        |--------------------------------------------------------------------------
        | Http Timeout
        |--------------------------------------------------------------------------
        |
        | Here you may customize the timeout for HTTP requests for receiving remote dumps.
        | The default is 120 seconds.
        |
        */
        'httpTimeout' => env('PROTECTOR_HTTP_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the server.
    |
    */
    'serverConfig' => [
        /*
        |--------------------------------------------------------------------------
        | Dump Endpoint Route
        |--------------------------------------------------------------------------
        |
        | Here you may customize the route for the dump endpoint.
        |
        */
        'dumpEndpointRoute' => env('PROTECTOR_DUMP_ENDPOINT_ROUTE', '/protector/exportDump'),

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
        'chunkSize' => env('PROTECTOR_CHUNK_SIZE', 20 * 1024 * 1024),
    ],

    'metadata' => [
        /*
        |--------------------------------------------------------------------------
        | Metadata Providers
        |--------------------------------------------------------------------------
        |
        | Here you may configure the metadata providers that will be used to generate
        | the metadata which is appended to the end of each dump file.
        |
        | Metadata related to the database connection will always be added by the DatabaseMetadataProvider.
        |
        */
        'providers' => [
            \Cybex\Protector\Classes\Metadata\Providers\ProtectorMetadataProvider::class,
            \Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider::class,
            \Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider::class,
            \Cybex\Protector\Classes\Metadata\Providers\JsonFileMetadataProvider::class,
        ],

        /*
        |--------------------------------------------------------------------------
        | Metadata from ENV value
        |--------------------------------------------------------------------------
        |
        | This .env value will be used by the EnvMetadataProvider to add metadata to the dump file.
        |
        */
        'envValue' => env('PROTECTOR_METADATA'),

        /*
        |--------------------------------------------------------------------------
        | Metadata from JSON File
        |--------------------------------------------------------------------------
        |
        | This JSON file will be used by the JsonFileMetadataProvider to add metadata to the dump file.
        |
        */
        'jsonFilePath' => env('PROTECTOR_METADATA_JSON_FILE_PATH', 'protector_metadata.json'),
    ],
];
