<?php

use Cybex\Protector\Enums\ExecutionMode;
use Cybex\Protector\Enums\ProtectorEnv;

/**
 *  All config values using {@link ProtectorEnv} can be set via .env keys.
 *  Take a look at the {@link ProtectorEnv} enum for all available .env keys.
 */
/*
|--------------------------------------------------------------------------
| Cleanup Configuration
|--------------------------------------------------------------------------
|
| Here you may configure the cleanup of different targets.
|
*/
return [
    /*
    |--------------------------------------------------------------------------
    | Protector Local Disk
    |--------------------------------------------------------------------------
    |
    | Here you may configure the deletion of temporary files on the protector_local disk.
    | All temporary Protector files which are older than 1 day will be deleted from the disk.
    |
    | Normally there should be no remnants of temporary files, but unexpected errors can cause temporary files to remain on the disk.
    |
    */
    'local_disk' => [
        /*
        |--------------------------------------------------------------------------
        | Command
        |--------------------------------------------------------------------------
        |
        | Here you may configure the command which will be executed.
        |
        */
        'command' => \Cybex\Protector\Commands\CleanupLocal::class,

        /*
        |--------------------------------------------------------------------------
        | Mode
        |--------------------------------------------------------------------------
        |
        | Here you may configure the cleanup mode. There are two modes available:
        | - sync: Will run synchronously everytime a local file is deleted. May impact performance.
        | - schedule: Will schedule the protector:flush-local command according to the cron expression below.
        |   This will run in the background, but will require you to run the Laravel Scheduler https://laravel.com/docs/master/scheduling#running-the-scheduler.
        */
        'mode' => ProtectorEnv::CLEANUP_LOCAL_DISK_MODE->get(default: ExecutionMode::SYNC->value),

        /*
        |--------------------------------------------------------------------------
        | Schedule
        |--------------------------------------------------------------------------
        |
        | Only applicable when in 'schedule' mode.
        |
        | Here you may configure a cron expression for how often the flush command will be scheduled.
        | Default is '0 0 * * *' for running every day at midnight.
        |
        */
        'schedule' => ProtectorEnv::CLEANUP_LOCAL_DISK_SCHEDULE->get(default: '0 0 * * *'),
    ],
];
