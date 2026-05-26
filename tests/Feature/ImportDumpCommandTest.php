<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class ImportDumpCommandTest extends TestCase
{
    protected Filesystem $disk;

    protected string $dumpEndpointUrl;
    protected string $shouldDownloadDump;
    protected string $shouldImportDump;
    protected static string $baseDirectory = 'dumps';

    protected const array DUMP_SOURCE_CHOICE = ['Download remote dump', 'Import existing dump'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);
        Config::set('protector.dump.disks.storage.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getFakeDumpDisk();

        $this->shouldDownloadDump = 'Do you want to download and import a fresh dump from the server or an existing dump?';
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

        $this->artisan('protector:import');
    }

    #[Test]
    public function getRemoteDumpOnImportDumpCommand(): void
    {
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.server.routeMiddleware', []);

        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->artisan('protector:import --remote')
            ->expectsConfirmation($this->shouldImportDump);

        $this->assertFileDoesNotExist($this->disk->path(static::$baseDirectory . '/remote_dump.sql'));

        $localFiles = Storage::disk($this->diskHelper->getLocalDiskName())->files($this->diskHelper->getLocalBaseDirectory());
        $this->assertCount(0, $localFiles);
    }

    #[Test]
    public function failGetRemoteOnDumpWithNoResponse(): void
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->artisan('protector:import --remote');
    }

    #[Test]
    public function failOptionFileOnNonExistingDump(): void
    {
        $fileName = 'thisDumpDoesNotExist.sql';

        $this->expectExceptionObject(new FileNotFoundException(path: sprintf('%s%s%s', static::$baseDirectory, DIRECTORY_SEPARATOR, $fileName)));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName));
    }

    #[Test]
    public function failOptionFileOnNonExistingAbsoluteFilePath(): void
    {
        $fileName = $this->disk->path('thisDumpDoesNotExist.sql');

        $this->expectExceptionObject(new FileNotFoundException(path: $fileName));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName));
    }

    #[Test]
    public function canImportDumpOnOptionFileWithExistingAbsoluteFilePath(): void
    {
        $fileName = $this->disk->path($this->protector->dumpFile('dump.sql'));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName))->assertOk();
    }

    #[Test]
    public function canImportDumpOnOptionFileWithExistingNestedRelativeFilePath(): void
    {
        $nestedRelativeFilePath = 'nested/dump.sql';
        $storageFilePath = sprintf('%s%s%s', static::$baseDirectory, DIRECTORY_SEPARATOR, $nestedRelativeFilePath);

        $this->disk->put($storageFilePath, file_get_contents(__DIR__ . '/../dumps/dump.sql'));

        $this->artisan(sprintf('protector:import --file=%s --force', $nestedRelativeFilePath))->assertOk();
    }

    #[Test]
    public function canImportDumpOnOptionLatest(): void
    {
        $this->artisan('protector:import --latest')->expectsConfirmation($this->shouldImportDump);

        $this->assertContains(
            sprintf('%s%sdump.sql', $this->diskHelper->getStorageBaseDirectory(), DIRECTORY_SEPARATOR),
            $this->protector->dumpFiles()->toArray()
        );
    }

    #[Test]
    public function failChooseImportDumpOnNoFilesInBaseDirectory(): void
    {
        $this->clearDumpDirectory();

        $this->expectException(EmptyBaseDirectoryException::class);

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    #[Test]
    public function chooseImportDumpWithOnlyOneFileInBaseDirectory(): void
    {
        DiskHelper::flushDumps(static::$baseDirectory . '/dump.sql');

        $this->assertCount(1, $this->protector->dumpFiles());

        $this->artisan('protector:import')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE)
            ->expectsConfirmation($this->shouldImportDump);
    }
}
