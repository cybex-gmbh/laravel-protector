<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Protector;
use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

//use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class TestCase extends OrchestraTestCase
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = app('protector');
    }

    /**
     * @return string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            ProtectorServiceProvider::class,
        ];
    }

    protected function getAccessibleReflectionMethod(string $method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass($this->protector);
        $method = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }

    /**
     * Allows a test to call a protected method.
     */
    protected function runProtectedMethod(string $methodName, array $params = []): mixed
    {
        $method = $this->getAccessibleReflectionMethod($methodName);

        return $method->invoke($this->protector, ...$params);
    }

    protected function setProtectedProperty(string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($this->protector, $propertyName);

        $property->setAccessible(true);
        $property->setValue($this->protector, $value);
    }

    protected function getFakeDumpDisk(): Filesystem
    {
        $disk = $this->getDumpDisk();
        $baseDirectory = $this->protector->getBaseDirectory();

        foreach (glob(getcwd().'/tests/dumps/*.sql') as $filename) {
            Storage::disk('local')->putFileAs($baseDirectory, $filename, basename($filename));
        }

        return $disk;
    }

    protected function getDumpDisk(): Filesystem
    {
        $diskName = config('protector.diskName', config('filesystems.default'));

        return Storage::fake($diskName);
    }

    protected function clearDumpDirectory(): void
    {
        $this->getDumpDisk()->deleteDirectory($this->protector->getBaseDirectory());
    }
}
