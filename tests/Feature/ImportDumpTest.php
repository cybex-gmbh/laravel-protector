<?php

namespace Cybex\Protector\Tests\Feature;

use Carbon\Carbon;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ImportDumpTest extends TestCase
{
    protected const string DUMP_DATE = '2022-06-29 12:43:24';

    protected string $fileName;
    protected Protector $protector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = app('protector');
        $this->fileName = 'dump.sql';
    }

    public static function provideDumpMetadata(): array
    {
        return [
            [
                'dump.sql',
                [
                    'meta' => [
                        'database' => [
                            'database' => 'protector-tests',
                            'connection' => 'mysql',
                            'maxPacketLength' => '8M',
                            'dumpedAtDate' => Carbon::parse(static::DUMP_DATE)->toDateTimeString(),
                        ],
                    ],
                ],
            ],
            [
                'dumpWithGit.sql',
                [
                    'meta' => [
                        'git' => [
                            'revision' => '2aae6c35c94fcfb415dbe95f408b9ce91ee846ed',
                            'branch' => 'feature/tests',
                            'revisionDate' => '2022-07-12 08:00:00 +0200',
                        ],
                        'database' => [
                            'database' => 'protector-tests',
                            'connection' => 'mysql',
                            'maxPacketLength' => '8M',
                            'dumpedAtDate' => Carbon::parse(static::DUMP_DATE)->toDateTimeString(),
                        ],
                    ],
                ],
            ],
            [
                'dumpWithoutMetadata.sql',
                [],
            ],
            [
                'dumpWithIncorrectMetadata.sql',
                false,
            ],
            [
                'legacyDump.sql',
                [
                    'options' => [
                        'no-data' => false,
                    ],
                    'meta' => [
                        'database' => 'protector-tests',
                        'connection' => 'mysql',
                        'maxPacketLength' => '8M',
                        'gitRevision' => '',
                        'gitBranch' => '',
                        'gitRevisionDate' => '',
                        'dumpedAtDate' => Carbon::parse(static::DUMP_DATE)->toDateTimeString(),
                    ],
                ],
            ],
        ];
    }

    public static function provideEmptyDumpsWhenReceivingTheLatestDumpName(): array
    {
        return [
            ['dump.sql', false],
            ['dumpWithGit.sql', true],
        ];
    }

    public static function provideEmptyDumpsForFlushingDumps(): array
    {
        return [
            [[], null],
            [['emptyDump.sql'], 'emptyDump.sql'],
        ];
    }

    #[Test]
    public function failOnProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->expectException(InvalidEnvironmentException::class);
        $this->protector->import($this->fileName);
    }

    #[Test]
    public function failOnInvalidConnectionConfig(): void
    {
        Config::set('database.connections');
        $this->expectException(InvalidConnectionException::class);
        app(ProtectorConfiguratorContract::class)->setConnectionName('invalid');
    }

    #[Test]
    public function throwsExceptionOnFileNotFound(): void
    {
        $path = 'thisFileDoesNotExist';

        $this->expectException(FailedReadingFromDiskException::class);
        $this->protector->import($path);
    }

    #[Test]
    public function throwsExceptionOnMysqlFailedShellCommand(): void
    {
        $connection = env('DB_CONNECTION');

        Config::set('database.default', $connection);
        Config::set(sprintf('database.connections.%s.host', $connection), 'protector.invalid');

        $this->expectException(FailedWipeException::class);
        $this->protector->import($this->fileName);
    }

    #[Test]
    #[DataProvider('provideEmptyDumpsWhenReceivingTheLatestDumpName')]
    public function canReturnLatestFileName(string $expectedFileName, bool $shouldModify): void
    {
        if ($shouldModify) {
            touch($this->storageDisk->path($expectedFileName), time() + 60);
        }

        $fileName = $this->protector->latestDumpName();

        $this->assertEquals($expectedFileName, $fileName);
        $this->assertIsString($fileName);
    }

    #[Test]
    public function throwsExceptionIfNoFileExists(): void
    {
        DiskHelper::flushStorage();
        $this->storageDisk->delete('legacyDump.sql');

        $this->expectException(EmptyDumpDirectoryException::class);
        $this->protector->latestDumpName();
    }

    #[Test]
    #[DataProvider('provideDumpMetadata')]
    public function verifyDumpDateMetadata(string $fileName, array|bool $expectedMetadata): void
    {
        $this->localDisk->writeStream($fileName, $this->storageDisk->readStream($fileName));

        $this->assertEquals($expectedMetadata, $this->runProtectedMethod('getDumpMetadata', [$fileName]));
    }

    #[Test]
    public function failGetDumpMetadataOnResponseHasNotEnoughLines(): void
    {
        $dumpName = 'emptyDump.sql';
        $this->localDisk->writeStream($dumpName, $this->storageDisk->readStream($dumpName));

        $this->assertFalse($this->runProtectedMethod('getDumpMetadata', [$dumpName]));
    }

    #[Test]
    #[DataProvider('provideEmptyDumpsForFlushingDumps')]
    public function flushStorage(array $expected, ?string $excludeFromFlush): void
    {
        $allFiles = DiskHelper::allStorageFiles();
        DiskHelper::flushStorage($excludeFromFlush);

        $dumpsAfterFlushing = $this->protector->dumpFiles()->toArray();

        $this->assertEquals($expected, $dumpsAfterFlushing);
        $this->assertContains('legacyDump.sql', $allFiles);
    }

    #[Test]
    public function importFromExplicitCustomDiskUsesGivenName(): void
    {
        $customDiskName = 'custom_import_disk';
        $customRelativeName = 'dump.sql';

        Config::set(sprintf('filesystems.disks.%s', $customDiskName), [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/' . $customDiskName),
            'throw' => true,
        ]);

        $customDisk = Storage::fake($customDiskName);
        $customDisk->put($customRelativeName, file_get_contents(__DIR__ . '/../dumps/dump.sql'));

        $this->protector->import($customRelativeName, $customDisk);

        $this->assertTrue($customDisk->exists($customRelativeName));
    }

    #[Test]
    public function dumpFilesWithMetadataUseUnknownConnectionWhenMetadataFileIsMissing(): void
    {
        $dumpFile = 'dump.sql';

        $this->storageDisk->delete($this->diskHelper->metadataFileName($dumpFile));

        $dumpFilesWithMetadata = $this->diskHelper->allStorageFilesWithMetadata();

        $this->assertSame([], $dumpFilesWithMetadata->get($dumpFile));
    }

    #[Test]
    public function dumpFilesWithMetadataPreferMetadataFilePayload(): void
    {
        $dumpFile = 'dump.sql';
        $metadataFilePayload = [
            'meta' => [
                'database' => [
                    'connection' => 'pgsql',
                ],
            ],
        ];

        $this->storageDisk->put($this->diskHelper->metadataFileName($dumpFile), json_encode($metadataFilePayload, JSON_UNESCAPED_UNICODE));

        $dumpFilesWithMetadata = $this->protector->dumpFilesWithMetadata();

        $this->assertEquals('pgsql', Arr::get($dumpFilesWithMetadata->get($dumpFile), 'meta.database.connection'));
    }
}
