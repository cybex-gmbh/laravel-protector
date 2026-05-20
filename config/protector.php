<?php

use Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\JsonFileMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\ProtectorMetadataProvider;
use Cybex\Protector\Enums\ProtectorEnv;

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
        'baseDirectory' => ProtectorEnv::BASE_DIRECTORY->get(default: 'protector'),
        // 'diskName' => ProtectorEnv::DISK_NAME->value(default: 'protector'),

        /*
        |--------------------------------------------------------------------------
        | Maximum Packet Length
        |--------------------------------------------------------------------------
        |
        | Here you may customize the maximum packet length for the database dump.
        | Note: The maximum packet length is defined by the system generating the dump.
        |
        */
        'maxPacketLength' => ProtectorEnv::MAX_PACKET_LENGTH->get(default: '8M'),

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
                ProtectorMetadataProvider::class,
                EnvMetadataProvider::class,
                GitMetadataProvider::class,
                JsonFileMetadataProvider::class,
            ],

            /*
            |--------------------------------------------------------------------------
            | Metadata from ENV value
            |--------------------------------------------------------------------------
            |
            | This .env value will be used by the EnvMetadataProvider to add metadata to the dump file.
            |
            */
            'envValue' => ProtectorEnv::METADATA->get(),

            /*
            |--------------------------------------------------------------------------
            | Metadata from JSON File
            |--------------------------------------------------------------------------
            |
            | This JSON file will be used by the JsonFileMetadataProvider to add metadata to the dump file.
            |
            */
            'jsonFilePath' => ProtectorEnv::METADATA_JSON_FILE_PATH->get(default: 'protector_metadata.json'),
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
        'dumpEndpointUrl' => ProtectorEnv::DUMP_ENDPOINT_URL->get(),

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
        'authToken' => ProtectorEnv::AUTH_TOKEN->get(),

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
        'dumpEndpointRoute' => ProtectorEnv::DUMP_ENDPOINT_ROUTE->get('/protector/exportDump'),

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
    ],
];
