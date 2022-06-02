<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Protector;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class GetLatestDumpNameTest extends BaseTest
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

    protected function setUp(): void
    {
        parent::setUp();

        $fakeDiskName = uniqid('protector');

        Config::set('protector.baseDirectory', 'protector');
        Config::set('protector.diskName', $fakeDiskName);

        $this->protector     = app('protector');
        $this->disk          = Storage::fake($fakeDiskName);
        $this->baseDirectory = Config::get('protector.baseDirectory');
    }

    /**
     * @test
     */
    public function returnsFileNameIfExists()
    {
        $path = sprintf('%s%s%s', $this->baseDirectory, DIRECTORY_SEPARATOR, 'test.txt');

        $this->disk->put($path, '');

        $this->assertEquals($path, $this->protector->getLatestDumpName());
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
