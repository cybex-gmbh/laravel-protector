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
     * @param $method
     *
     * @return ReflectionMethod
     */
    protected function getAccessibleReflectionMethod($method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass($this->protector);
        $method              = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }

    /**
     * Allows a test to call a protected method.
     *
     * @param string $methodName
     * @param array $params
     * @return mixed
     */
    protected function runProtectedMethod(string $methodName, array $params): mixed
    {
        $method = $this->getAccessibleReflectionMethod($methodName);
        return $method->invoke($this->protector, ...$params);
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
