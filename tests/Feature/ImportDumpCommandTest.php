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
use PHPUnit\Framework\Attributes\Test;

class ImportDumpCommandTest extends TestCase
{
    protected Filesystem $disk;

    protected string $dumpEndpointUrl;
    protected string $shouldDownloadDump;
    protected string $shouldImportDump;
    protected static string $baseDirectory = 'dumps';

    protected const array DUMP_SOURCE_CHOICE = ['Download remote dump', 'Import existing local dump'];

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

    #[Test]
    public function failOnProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->expectException(InvalidEnvironmentException::class);

        $this->artisan('protector:import');
    }

    #[Test]
    public function failOnOptionForceIncorrectlySet(): void
    {
        $this->artisan('protector:import --force')->assertFailed();
    }

    #[Test]
    public function failOnNoDumpHasSpecifiedConnection(): void
    {
        $this->expectException(InvalidConnectionException::class);

        $this->artisan('protector:import --connection=sqlite')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    #[Test]
    public function failSettingConnectionNameOnNoConnectionsAreConfigured(): void
    {
        Config::set('database.connections');

        $this->expectException(InvalidConnectionException::class);

        $this->artisan('protector:import')->assertFailed();
    }

    #[Test]
    public function getRemoteDumpOnImportDumpCommand(): void
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

    #[Test]
    public function canGetRemoteDumpWithFlushOptionEnabled(): void
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

    #[Test]
    public function failGetRemoteOnDumpWithNoResponse(): void
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->artisan('protector:import --remote')->assertFailed();
    }

    #[Test]
    public function failOptionFileOnNonExistingDump(): void
    {
        $fileName = 'thisDumpDoesNotExist.sql';

        $this->expectExceptionObject(new FileNotFoundException(path: sprintf('%s%s%s', static::$baseDirectory, DIRECTORY_SEPARATOR, $fileName)));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName))->assertFailed();
    }

    #[Test]
    public function failOptionFileOnNonExistingAbsoluteFilePath(): void
    {
        $fileName = $this->disk->path('thisDumpDoesNotExist.sql');

        $this->expectExceptionObject(new FileNotFoundException(path: $fileName));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName))->assertFailed();
    }

    #[Test]
    public function canImportDumpOnOptionFileWithExistingAbsoluteFilePath(): void
    {
        $fileName = $this->disk->path($this->protector->getDumpFile('dump.sql'));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName))->assertOk();
    }

    #[Test]
    public function canImportDumpOnOptionLatest(): void
    {
        $this->artisan('protector:import --latest')->expectsConfirmation($this->shouldImportDump);

        $this->assertEquals(
            sprintf('%s%sdump.sql', $this->protector->getDiskBaseDirectory(), DIRECTORY_SEPARATOR),
            $this->protector->getLatestDumpName()
        );
    }

    #[Test]
    public function failChooseImportDumpOnNoFilesInBaseDirectory(): void
    {
        $this->clearDumpDirectory();

        $this->expectException(EmptyBaseDirectoryException::class);

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE)->assertFailed();
    }

    #[Test]
    public function chooseImportDumpWithOnlyOneFileInBaseDirectory(): void
    {
        $this->protector->flush(static::$baseDirectory . '/dump.sql');

        $this->assertCount(1, $this->protector->getDumpFiles());

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE)
            ->expectsOutput('Using file "' . static::$baseDirectory . '/dump.sql" because there are no other dumps.')
            ->expectsConfirmation($this->shouldImportDump);
    }
}
