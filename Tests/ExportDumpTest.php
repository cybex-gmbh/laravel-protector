<?php

use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ExportDumpTest extends BaseTest
{
    protected Filesystem $disk;

    protected string $baseDirectory;
    protected string $filePath;
    protected string $emptyDumpPath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.baseDirectory', 'protector');

        $this->disk = Storage::disk('local');
        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath = sprintf('%s/dump.sql', $this->baseDirectory);
        $this->emptyDumpPath = 'testDumps/dump.sql';
    }

    /**
     * @test
     */
    public function createDestinationFilePath()
    {
        Config::set('protector.baseDirectory', 'noDumps');
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__);
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

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__, __FUNCTION__);
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
    public function failGeneratingDumpWhenTryingToConnectToDatabase()
    {
        // Provide an database connection to a non-existing database.
        Config::set('database.connections.invalid', [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => 'invalid_database_name',
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
        ]);

        // Configure protector to the invalid database connection.
        $this->protector->withConnectionName('invalid');

        // Expect an exception when trying to connect and determine if the connected database is a MariaDB database.
        $this->expectException(PDOException::class);
        $this->runProtectedMethod('generateDump', [['no-data' => true]]);
    }
}
