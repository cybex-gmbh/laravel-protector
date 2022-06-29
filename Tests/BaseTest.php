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
     * @param $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        Storage::disk('local')->put('protector/dump.sql', '');
    }

    protected function usesMultipleDumps()
    {
        Storage::disk('local')->put('protector/secondDump.sql', '');
    }
}
