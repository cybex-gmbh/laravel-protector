<?php

namespace Cybex\Protector\Tests\feature;

use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDumpTest extends TestCase
{
    protected Filesystem $disk;

    protected string $baseDirectory;
    protected string $filePath;
    protected string $emptyDumpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = $this->getFakeDumpDisk();

        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath      = sprintf('%s/dump.sql', $this->baseDirectory);
        $this->emptyDumpPath = 'testDumps/dump.sql';
    }

    /**
     * @test
     */
    public function createDestinationFilePath()
    {
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
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

        $filePath            = $this->protector->createDestinationFilePath(__FUNCTION__, __FUNCTION__);
        $destinationFilePath = $this->disk->path($filePath);

        $this->runProtectedMethod('createDirectory', [$filePath, $this->disk]);
        $this->assertDirectoryExists($destinationFilePath);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaData()
    {
        $dumpDate = now();

        Carbon::setTestNow($dumpDate);

        $metaData = $this->protector->getMetaData();

        $this->assertIsArray($metaData);
        $this->assertEquals($dumpDate, $metaData['dumpedAtDate']);
    }

    /**
     * @test
     */
    public function canCreateDumpMetaDataUsingCache()
    {
        $metaData = $this->protector->getMetaData();

        $metaData['hello'] = 'world';

        $this->setProtectedProperty('metaDataCache', $metaData);

        $cachedMetaData = $this->protector->getMetaData();

        $this->assertEquals($metaData, $cachedMetaData);

        $newMetaData = $this->protector->getMetaData(refresh: true);

        $this->assertNotEquals($metaData, $newMetaData);
    }

    /**
     * @test
     */
    public function failGeneratingDumpWhenTryingToConnectToDatabase()
    {
        // Provide an database connection to a non-existing database.
        Config::set('database.connections.invalid', [
            'driver'   => 'mysql',
            'url'      => env('DATABASE_URL'),
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '3306'),
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

    /**
     * @test
     */
    public function createsStreamedFileDownloadResponse()
    {
        Config::set('protector.routeMiddleware', []);

        $response = $this->protector->generateFileDownloadResponse(new Request(), null);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
