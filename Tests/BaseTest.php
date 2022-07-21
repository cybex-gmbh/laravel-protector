<?php

use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

abstract class BaseTest extends TestCase
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
     * @param $fileNames
     * @return void
     */
    protected function provideDynamicDumps($fileNames): void
    {
        $directoryName = 'dynamicDumps';
        $disk          = Storage::disk('local');

        $disk->deleteDirectory($directoryName);

        Config::set('protector.baseDirectory', 'dynamicDumps');
        $disk->makeDirectory($directoryName);

        foreach ($fileNames as $fileName) {
            $disk->put(sprintf('%s%s%s', $directoryName, DIRECTORY_SEPARATOR, $fileName), '');
        }
    }

    /**
     * @return void
     */
    protected function usesEmptyFolder(): void
    {
        Storage::disk('local')->makeDirectory('noDumps');
    }
}
