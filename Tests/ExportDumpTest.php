<?php

use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedMysqlCommandException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Protector;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ExportDumpTest extends BaseTest
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    protected Filesystem $disk;

    protected string $baseDirectory;
    protected string $filePath;
    protected string $emptyDumpPath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.baseDirectory', 'protector');

        $this->protector     = app('protector');
        $this->disk          = Storage::disk('local');
        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath      = sprintf('%s/dump.sql', $this->baseDirectory);
        $this->emptyDumpPath = 'testDumps/dump.sql';
    }

    /**
     * @test
     */
    public function createDestinationFilePath()
    {
        Config::set('protector.baseDirectory', 'noDumps');
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath            = $this->protector->createDestinationFilePath(__FUNCTION__);
        $destinationFilePath = $this->disk->path($filePath);

        $this->runProtectedMethod('createDirectory', [$filePath, $this->disk]);
        $this->assertDirectoryExists($destinationFilePath);
    }

    /**
     * @test
     */
    public function createDestinationFilePathWithSubFolder()
    {
        Config::set('protector.baseDirectory', 'noDumps');

        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath            = $this->protector->createDestinationFilePath(__FUNCTION__, __FUNCTION__);
        $destinationFilePath = $this->disk->path($filePath);

        $this->runProtectedMethod('createDirectory', [$filePath, $this->disk]);
        $this->assertDirectoryExists($destinationFilePath);
    }

    /**
     * @test
     */
    public function failDirectoryCreationOnExistingFile()
    {
        $path = 'protector/dump.sql';

        $this->expectException(FailedCreatingDestinationPathException::class);
        $this->runProtectedMethod('createDirectory', [$path, $this->disk]);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaData()
    {
        $metaData = $this->runProtectedMethod('createMetaData', [false]);

        $this->assertIsArray($metaData);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaDataUsingCache()
    {
        $this->runProtectedMethod('createMetaData');

        $metaData = $this->runProtectedMethod('createMetaData', [false]);

        $this->assertIsArray($metaData);
    }

    /**
     * @test
     */
    public function failGeneratingDumpOnFailedShellCommand()
    {
        $this->disk->put($this->emptyDumpPath, __FUNCTION__);

        $this->expectException(FailedMysqlCommandException::class);
        $this->runProtectedMethod('generateDump', [['no-data' => true]]);
    }

    /**
     * @test
     */
    public function failOnDumpHasNoConnectionConfigured()
    {
        Config::set('database.connections', null);
        $this->protector->configure();

        $this->expectException(InvalidConnectionException::class);
        $this->protector->createDump();
    }

    /**
     * @test
     */
    public function failGetDestinationFilePathWhenGeneratingDump()
    {
        $this->expectException(FailedMysqlCommandException::class);
        $this->protector->createDump();
    }
}
