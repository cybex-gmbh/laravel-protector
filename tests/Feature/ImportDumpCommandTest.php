<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class ImportDumpCommandTest extends TestCase
{
    protected Filesystem $disk;

    protected string $dumpEndpointUrl;
    protected string $shouldDownloadDump;
    protected string $shouldImportDump;
    protected static string $baseDirectory = 'dumps';

    protected const DUMP_SOURCE_CHOICE = ['Download remote dump', 'Import existing local dump'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);
        Config::set('protector.dump.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getFakeDumpDisk();

        $this->shouldDownloadDump = 'Do you want to download and import a fresh dump from the server or an existing local dump?';
        $this->shouldImportDump = sprintf(
            'Are you sure that you want to import the dump into the database: %s?',
            $this->protector->getDatabaseName()
        );
    }

    /**
     * @test
     */
    public function failOnProductionEnvironment()
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->expectException(InvalidEnvironmentException::class);

        $this->artisan('protector:import');
    }

    /**
     * @test
     */
    public function failOnOptionForceIncorrectlySet()
    {
        $this->artisan('protector:import --force')->assertFailed();
    }

    /**
     * @test
     */
    public function failOnNoDumpHasSpecifiedConnection()
    {
        $this->expectException(InvalidConnectionException::class);

        $this->artisan('protector:import --connection=sqlite')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    /**
     * @test
     */
    public function failSettingConnectionNameOnNoConnectionsAreConfigured()
    {
        Config::set('database.connections', null);

        $this->expectException(InvalidConnectionException::class);

        $this->artisan('protector:import');
    }

    /**
     * @test
     */
    public function getRemoteDumpOnImportDumpCommand()
    {
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.server.routeMiddleware', []);

        Http::fake([
            $this->dumpEndpointUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
        ]);

        $this->artisan('protector:import --remote')
            ->expectsConfirmation($this->shouldImportDump);

        $this->assertFileExists($this->disk->path(static::$baseDirectory . '/remote_dump.sql'));
    }

    /**
     * @test
     */
    public function canGetRemoteDumpWithFlushOptionEnabled()
    {
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.server.routeMiddleware', []);

        Http::fake([
            $this->dumpEndpointUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
        ]);

        $this->artisan('protector:import --remote --flush')
            ->expectsConfirmation($this->shouldImportDump);

        $this->assertEquals(
            [sprintf('%s%sremote_dump.sql', Config::get('protector.dump.baseDirectory'), DIRECTORY_SEPARATOR)],
            $this->protector->getDumpFiles()->toArray()
        );
    }

    /**
     * @test
     */
    public function failGetRemoteOnDumpWithNoResponse()
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->artisan('protector:import --remote')->assertFailed();
    }

    /**
     * @test
     */
    public function failOptionFileOnNonExistingDump()
    {
        $fileName = 'thisDumpDoesNotExist.sql';

        $this->expectExceptionObject(new FileNotFoundException(path: sprintf('%s%s%s', static::$baseDirectory, DIRECTORY_SEPARATOR, $fileName)));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName))->assertFailed();
    }


    /**
     * @test
     */
    public function canImportDumpOnOptionLatest()
    {
        $this->artisan('protector:import --latest')->expectsConfirmation($this->shouldImportDump);

        $this->assertEquals(
            sprintf('%s%sdump.sql', $this->protector->getDiskBaseDirectory(), DIRECTORY_SEPARATOR),
            $this->protector->getLatestDumpName()
        );
    }

    /**
     * @test
     */
    public function failChooseImportDumpOnNoFilesInBaseDirectory()
    {
        $this->clearDumpDirectory();

        $this->expectException(EmptyBaseDirectoryException::class);

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    /**
     * @test
     */
    public function chooseImportDumpWithOnlyOneFileInBaseDirectory()
    {
        $this->protector->flush(static::$baseDirectory . '/dump.sql');

        $this->assertCount(1, $this->protector->getDumpFiles());

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE)
            ->expectsOutput('Using file "' . static::$baseDirectory . '/dump.sql" because there are no other dumps.')
            ->expectsConfirmation($this->shouldImportDump);
    }
}
