<?php

use Cybex\Protector\Classes\Metadata\Providers\EnvMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\GitMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\JsonFileMetadataProvider;
use Cybex\Protector\Classes\Metadata\Providers\ProtectorMetadataProvider;
use Cybex\Protector\Enums\ProtectorEnv;

/**
 *  All config values using {@link ProtectorEnv} can be set via .env keys.
 *  Take a look at the {@link ProtectorEnv} enum for all available .env keys.
 */
/*
|--------------------------------------------------------------------------
| Dump Configuration
|--------------------------------------------------------------------------
|
| Here you may configure settings related to the database dump.
|
*/
return [
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
];
