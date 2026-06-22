<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ImportDumpCommandTest extends TestCase
{
    protected string $dumpEndpointUrl;
    protected string $shouldDownloadDump;
    protected string $shouldImportDump;
    protected const array DUMP_SOURCE_CHOICE = ['Download remote dump', 'Import existing dump'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);

        $this->shouldDownloadDump = 'Do you want to download and import a fresh dump from the server or an existing dump?';
        $this->shouldImportDump = sprintf(
            'Are you sure that you want to import a dump into the database: %s?',
            $this->protector->getDatabaseName()
        );
    }

    #[Test]
    public function failOnProductionEnvironment(): void
    {
        App::shouldReceive('environment')->andReturn('production');

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
            ->expectsConfirmation($this->shouldImportDump, 'yes')
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
            ->expectsConfirmation($this->shouldImportDump, 'yes');

        $this->assertFileDoesNotExist($this->storageDisk->path('remote_dump.sql'));

        $localFiles = $this->localDisk->files();
        $this->assertCount(0, $localFiles);
    }

    #[Test]
    public function failGetRemoteOnDumpWithNoResponse(): void
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->artisan('protector:import --remote --force');
    }

    #[Test]
    public function failOptionFileOnNonExistingDump(): void
    {
        $fileName = 'thisDumpDoesNotExist.sql';

        $this->expectExceptionObject(new FileNotFoundException(path: $fileName));

        $this->artisan(sprintf('protector:import --file=%s --force', $fileName));
    }

    #[Test]
    public function canImportDumpOnOptionFileWithExistingNestedRelativeFilePath(): void
    {
        $nestedRelativeFilePath = 'nested/dump.sql';
        $this->storageDisk->put($nestedRelativeFilePath, file_get_contents(__DIR__ . '/../dumps/dump.sql'));

        $this->artisan(sprintf('protector:import --file=%s --force', $nestedRelativeFilePath))->assertOk();
    }

    #[Test]
    public function canImportDumpOnOptionLatest(): void
    {
        $this->artisan('protector:import --latest')->expectsConfirmation($this->shouldImportDump, 'yes');

        $this->assertContains(
            'dump.sql',
            $this->protector->dumpFiles()->toArray()
        );
    }

    #[Test]
    public function failChooseImportDumpOnNoFilesInDumpDirectory(): void
    {
        DiskHelper::flushStorage();
        $this->storageDisk->delete('legacyDump.sql');

        $this->expectException(EmptyDumpDirectoryException::class);

        $this->artisan('protector:import')
            ->expectsConfirmation($this->shouldImportDump, 'yes')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    #[Test]
    public function chooseImportDumpWithOnlyOneFileInDumpDirectory(): void
    {
        DiskHelper::flushStorage(excludeFile: 'dump.sql');
        $this->storageDisk->delete('legacyDump.sql');

        $this->assertCount(1, $this->protector->dumpFiles());

        $this->artisan('protector:import')
            ->expectsConfirmation($this->shouldImportDump, 'yes')
            ->expectsChoice($this->shouldDownloadDump, 2, static::DUMP_SOURCE_CHOICE);
    }

    #[Test]
    public function usesPassedDisk(): void
    {
        $this->artisan('protector:import --file=dump.sql --force')->assertOk();

        $this->expectException(FileNotFoundException::class);
        $this->artisan('protector:import --file=dump.sql --disk=local --force');
    }

    #[Test]
    public function doesNotCopyOnNoCopy(): void
    {
        // Delete root directory.
        $this->localDisk->deleteDirectory('');
        $this->assertDirectoryDoesNotExist($this->localDisk->path(''));

        $this->artisan('protector:import --file=dump.sql --no-copy --force')->assertOk();

        $this->assertDirectoryDoesNotExist($this->localDisk->path(''));
    }

    #[Test]
    #[DataProvider('provideInvalidParameters')]
    public function failOnInvalidParameters(string $fileOption): void
    {
        $this->artisan(sprintf('protector:import %s', $fileOption))->assertFailed();
    }

    public static function provideInvalidParameters(): array
    {
        return [
            ['--remote --file=dump.sql'],
            ['--remote --latest'],
            ['--remote --file=dump.sql --latest'],
            ['--disk=local'],
            ['--disk=local --no-copy'],
            ['--no-copy'],
            ['--file=""'],
            ['--file='],
        ];
    }
}
