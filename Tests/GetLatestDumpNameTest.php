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

        Config::set('protector.baseDirectory', 'dynamicDumps');

        $this->protector     = app('protector');
        $this->disk          = $this->protector->getDisk();
        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath      = sprintf('%s%sdump.sql', $this->baseDirectory, DIRECTORY_SEPARATOR);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->disk->deleteDirectory($this->baseDirectory);
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function returnsFileNameIfExists()
    {
        touch($this->disk->path($this->filePath));

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($this->filePath, $fileName);
        $this->assertIsString($fileName);
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function returnsFileNameIfMultipleDumpsExist()
    {
        $secondDumpFilePath = sprintf('%s%ssecondDump.sql', $this->baseDirectory, DIRECTORY_SEPARATOR);

        $this->disk->put($secondDumpFilePath, '');
        touch($this->disk->path($secondDumpFilePath), time() + 60);

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($secondDumpFilePath, $fileName);
        $this->assertIsString($fileName);
    }

    /**
     * @test
     * @define-env usesEmptyFolder
     */
    public function throwsExceptionIfNoFileExists()
    {
        Config::set('protector.baseDirectory', 'noDumps');
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $this->expectException(FileNotFoundException::class);
        $this->protector->getLatestDumpName();
    }
}
