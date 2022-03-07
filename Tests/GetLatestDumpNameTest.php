<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Protector;
use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;

class GetLatestDumpNameTest extends TestCase
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    /**
     *  Configured file system disk.
     */
    protected FilesystemAdapter $disk;

    /**
     * Base directory for dumps.
     */
    protected string $baseDirectory;

    protected function getPackageProviders($app): array
    {
        return [
            ProtectorServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('baseDirectory', 'protector');

        $this->protector     = app('protector');
        $this->disk          = $this->protector->getDisk();
        $this->baseDirectory = Config::get('baseDirectory');
    }

    /**
     * @test
     */
    public function returnsFileNameIfExists()
    {
        $path = sprintf('%s%s%s', $this->baseDirectory, DIRECTORY_SEPARATOR, 'test.txt');

        $this->disk->put($path, '');

        $file = $this->protector->getLatestDumpName();
        $this->assertIsString($file);
    }

    /**
     * @test
     */
    public function throwsExceptionIfNoFileExists()
    {
        $this->disk->deleteDirectory($this->baseDirectory);

        $this->expectException(FileNotFoundException::class);
        $this->protector->getLatestDumpName();
    }
}
