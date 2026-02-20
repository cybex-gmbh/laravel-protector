<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
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

    protected string $serverUrl;
    protected string $shouldDownloadDump;
    protected string $shouldImportDump;
    protected static string $baseDirectory = 'dumps';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.baseDirectory', 'protector');
        Config::set('protector.remoteEndpoint.serverUrl', 'protector.invalid/protector/exportDump');
        Config::set('protector.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getFakeDumpDisk();
        $this->serverUrl = $this->protector->getServerUrl();

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
            ->expectsChoice($this->shouldDownloadDump, 2, ['Download remote dump', 'Import existing local dump']);
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
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

        $serverUrl = $this->protector->getServerUrl();

        Http::fake([
            $serverUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
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
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

        Http::fake([
            $this->serverUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
        ]);

        $this->artisan('protector:import --remote --flush')
            ->expectsConfirmation($this->shouldImportDump);

        $this->assertEquals(
            [sprintf('%s%sremote_dump.sql', Config::get('protector.baseDirectory'), DIRECTORY_SEPARATOR)],
            $this->protector->getDumpFiles()
        );
    }

    /**
     * @test
     */
    public function failGetRemoteOnDumpWithNoResponse()
    {
        $this->artisan('protector:import --remote')
            ->expectsOutput(
                "Error retrieving dump from remote server: Could not fetch database from remote server: The scheme '' is not supported."
            )
            ->assertFailed();
    }

    /**
     * @test
     */
    public function failOptionFileOnNonExistingDump()
    {
        $fileName = 'thisDumpDoesNotExist.sql';

        $this->artisan(sprintf('protector:import --file=%s --ignore-connection-filter --force', $fileName))
            ->expectsOutput(sprintf('The file "%s" was not found.', $fileName));
    }

    /**
     * @test
     */
    public function failOptionDumpOnNonExistingDump()
    {
        $this->expectException(FileNotFoundException::class);

        $this->artisan('protector:import --dump=thisFileDoesNotExist');
    }

    /**
     * @test
     */
    public function canImportDumpOnOptionLatest()
    {
        $this->artisan('protector:import --latest')->expectsConfirmation($this->shouldImportDump);
        $this->assertEquals(
            sprintf('%s%sdump.sql', $this->protector->getBaseDirectory(), DIRECTORY_SEPARATOR),
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
            ->expectsChoice($this->shouldDownloadDump, 2, ['Download remote Dump']);
    }

    /**
     * @test
     */
    public function chooseImportDumpWithOnlyOneFileInBaseDirectory()
    {
        $this->protector->flush(static::$baseDirectory . '/dump.sql');

        $this->assertCount(1, $this->protector->getDumpFiles());

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, ['Download remote dump', 'Import existing local dump'])
            ->expectsOutput('Using file "' . static::$baseDirectory . '/dump.sql" because there are no other dumps.')
            ->expectsConfirmation($this->shouldImportDump);
    }
}
