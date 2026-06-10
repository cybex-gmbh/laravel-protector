<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Protector;
use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use function Orchestra\Testbench\package_path;

class TestCase extends OrchestraTestCase
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;
    protected DiskHelperContract $diskHelper;
    protected Filesystem $localDisk;
    protected Filesystem $storageDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = app('protector');
        $this->diskHelper = app(DiskHelperContract::class);
        $this->localDisk = $this->getLocalDisk();
        $this->storageDisk = $this->getStorageDiskWithFiles();
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

    protected function getApplicationBasePath(): string
    {
        return package_path() . '/vendor/orchestra/testbench-core/laravel/';
    }

    /**
     * @throws ReflectionException
     */
    protected function getAccessibleReflectionMethod(string $method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass($this->protector);

        return $reflectionProtector->getMethod($method);
    }

    /**
     * Allows a test to call a protected method.
     *
     * @param string $methodName
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    protected function runProtectedMethod(string $methodName, array $params = []): mixed
    {
        $method = $this->getAccessibleReflectionMethod($methodName);

        return $method->invoke($this->protector, ...$params);
    }

    /**
     * @throws ReflectionException
     */
    protected function setProtectedProperty(string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($this->protector, $propertyName);

        $property->setValue($this->protector, $value);
    }

    protected function getStorageDiskWithFiles(): Filesystem
    {
        $disk = $this->getStorageDisk();

        foreach (glob(__DIR__ . '/dumps/*.sql') as $filename) {
            $disk->put(basename($filename), file_get_contents($filename));
        }

        return $disk;
    }

    protected function getStorageDisk(): Filesystem
    {
        return Storage::fake($this->diskHelper->getStorageDiskName());
    }

    protected function getLocalDisk(): Filesystem
    {
        return Storage::fake($this->diskHelper->getLocalDiskName());
    }

    protected function clearLocal(): void
    {
        $this->getStorageDisk()->deleteDirectory('');
    }
}
