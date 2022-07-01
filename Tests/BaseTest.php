<?php

use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class BaseTest extends Orchestra
{
    /**
     * @return string
     */
    protected function getBasePath(): string
    {
        return __DIR__ . '/scaffolds/includes-protector';
    }

    /**
     * @param $app
     * @return string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            ProtectorServiceProvider::class,
        ];
    }

    /**
     * @return void
     */
    protected function usesEmptyDump(): void
    {
        $directoryName = 'dynamicDumps';

        Storage::disk('local')->makeDirectory($directoryName);
        Storage::disk('local')->put(sprintf('%s%sdump.sql', $directoryName, DIRECTORY_SEPARATOR), '');
    }

    /**
     * @return void
     */
    protected function usesEmptyFolder(): void
    {
        Storage::disk('local')->makeDirectory('noDumps');
    }
}
