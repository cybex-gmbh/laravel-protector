<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dump Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure settings related to the database dump.
    |
    */
    'dump' => [
        /*
        |--------------------------------------------------------------------------
        | File Name
        |--------------------------------------------------------------------------
        |
        | Here you may customize the database dump file name.
        | Note: The file name is defined by the system generating the dump.
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
        | Disk Configuration
        |--------------------------------------------------------------------------
        |
        | Here you may customize the base directory and the disk in which database dumps are stored.
        | By default, the default filesystem disk stated in your filesystems-config will be used.
        |
        */
        'baseDirectory' => env('PROTECTOR_DUMP_BASE_DIRECTORY', 'protector'),
        // 'diskName' => env('PROTECTOR_DUMP_DISK_NAME', 'protector'),

        /*
        |--------------------------------------------------------------------------
        | Maximum Packet Length
        |--------------------------------------------------------------------------
        |
        | Here you may customize the maximum packet length for the database dump.
        | Note: The maximum packet length is defined by the system generating the dump.
        |
        */
        'maxPacketLength' => env('PROTECTOR_DUMP_MAX_PACKET_LENGTH', '8M'),

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        |
        | Here you may customize the metadata appended at the end of a database dump.
        | Note: The metadata is defined by the system generating the dump.
        |
        */
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
            'envValue' => env('PROTECTOR_DUMP_METADATA'),

            /*
            |--------------------------------------------------------------------------
            | Metadata from JSON File
            |--------------------------------------------------------------------------
            |
            | This JSON file will be used by the JsonFileMetadataProvider to add metadata to the dump file.
            |
            */
            'jsonFilePath' => env('PROTECTOR_DUMP_METADATA_JSON_FILE_PATH', 'protector_metadata.json'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the request for downloading the dump.
    |
    */
    'client' => [
        /*
        |--------------------------------------------------------------------------
        | Dump Endpoint URL
        |--------------------------------------------------------------------------
        |
        | This value will be used as the endpoint for retrieving remote dumps.
        | The dump endpoint URL will be given to you by a server admin.
        | The .env key name should not be changed.
        |
        */
        'dumpEndpointUrl' => env('PROTECTOR_CLIENT_DUMP_ENDPOINT_URL'),

        /*
        |--------------------------------------------------------------------------
        | Auth Token
        |--------------------------------------------------------------------------
        |
        | This value will be used to authenticate requests for retrieving remote dumps.
        | The auth token will be given to you by a server admin.
        | The .env key name should not be changed.
        |
        */
        'authToken' => env('PROTECTOR_CLIENT_AUTH_TOKEN'),

        /*
        |--------------------------------------------------------------------------
        | Private Key
        |--------------------------------------------------------------------------
        |
        | This value will be used to decrypt a remote database dump.
        | The private key is retrieved by running "php artisan protector:keys". The public key should be transmitted to a server admin.
        | The .env key name should not be changed.
        |
        */
        'privateKey' => env('PROTECTOR_CLIENT_PRIVATE_KEY'),

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
        'basicAuthCredentials' => env('PROTECTOR_CLIENT_BASIC_AUTH_CREDENTIALS'),

        /*
        |--------------------------------------------------------------------------
        | Http Timeout
        |--------------------------------------------------------------------------
        |
        | Here you may customize the timeout for HTTP requests for receiving remote dumps.
        | The default is 120 seconds.
        |
        */
        'httpTimeout' => env('PROTECTOR_CLIENT_HTTP_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the server.
    |
    */
    'server' => [
        /*
        |--------------------------------------------------------------------------
        | Dump Endpoint Route
        |--------------------------------------------------------------------------
        |
        | Here you may customize the route for the dump endpoint.
        |
        */
        'dumpEndpointRoute' => env('PROTECTOR_SERVER_DUMP_ENDPOINT_ROUTE', '/protector/exportDump'),

        /*
        |--------------------------------------------------------------------------
        | Route Middleware
        |--------------------------------------------------------------------------
        |
        | Here you may customize middleware that will be applied.
        | By default, the auth:sanctum middleware is active and prevents the dump API from being public!
        | Note: The middleware of client and server should match, because it also controls whether dumps are encrypted or not.
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
        'chunkSize' => env('PROTECTOR_SERVER_CHUNK_SIZE', 20 * 1024 * 1024),
    ],
];
