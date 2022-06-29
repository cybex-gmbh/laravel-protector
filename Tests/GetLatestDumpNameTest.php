<?php

namespace Cybex\Protector\Tests;

use BaseTest;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Protector;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;

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

        Config::set('protector.baseDirectory', 'protector');

        $this->protector     = app('protector');
        $this->disk          = $this->protector->getDisk();
        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath      = sprintf('%s/dump.sql', $this->baseDirectory);
    }

    /**
     * @test
     */
    public function returnsFileNameIfExists()
    {
        $this->disk->put($this->filePath, '');

        $file = $this->protector->getLatestDumpName();
        $this->assertIsString($file);
    }

    /**
     * @test
     */
    public function returnsFileNameIfMultipleDumpsExist()
    {
        sleep(1);
        $secondDumpFilePath = sprintf('%s/secondDump.sql', $this->baseDirectory);

        $this->disk->put($secondDumpFilePath, '');

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($secondDumpFilePath, $fileName);
        $this->assertIsString($fileName);
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
