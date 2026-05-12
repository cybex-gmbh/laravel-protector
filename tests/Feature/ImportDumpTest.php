<?php

namespace Cybex\Protector\Tests\Feature;

use Carbon\Carbon;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;

class ImportDumpTest extends TestCase
{
    const DUMP_DATE = '2022-06-29 12:43:24';

    protected Filesystem $disk;
    protected static string $baseDirectory = 'dumps';
    protected string $filePath;
    protected Protector $protector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = app('protector');

        Config::set('protector.dump.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getFakeDumpDisk();

        $this->filePath = __DIR__ . '/../dumps/dump.sql';
    }

    public static function provideDumpMetadata(): array
    {
        return [
            [
                static::$baseDirectory . "/dump.sql",
                [
                    'meta' => [
                        'database' => [
                            'database' => 'protector-tests',
                            'connection' => 'mysql',
                            'maxPacketLength' => '8M',
                            'dumpedAtDate' => Carbon::parse(static::DUMP_DATE)->toDateTimeString(),
                        ],
                        'git' => [
                            'revision' => '',
                            'branch' => '',
                            'revisionDate' => '',
                        ],
                    ]
                ]
            ],
            [
                static::$baseDirectory . "/dumpWithGit.sql",
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
                    ]
                ]
            ],
            [
                static::$baseDirectory . "/dumpWithoutMetadata.sql",
                []
            ],
            [
                static::$baseDirectory . "/dumpWithIncorrectMetadata.sql",
                false
            ],
            [
                static::$baseDirectory . "/legacyDump.sql",
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
                    ]
                ]
            ]
        ];
    }

    public static function provideEmptyDumpsWhenReceivingTheLatestDumpName(): array
    {
        return [
            [
                static::$baseDirectory . '/dump.sql',
                false
            ],
            [
                static::$baseDirectory . '/secondDump.sql',
                true
            ]
        ];
    }

    public static function provideEmptyDumpsForFlushingDumps(): array
    {
        return [
            [
                [],
                null
            ],
            [
                [static::$baseDirectory . '/emptyDump.sql'],
                static::$baseDirectory . '/emptyDump.sql'
            ]
        ];
    }

    /**
     * @test
     */
    public function failOnProductionEnvironment()
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->expectException(InvalidEnvironmentException::class);
        $this->protector->importDump($this->filePath);
    }

    /**
     * @test
     */
    public function failOnInvalidConnectionConfig()
    {
        Config::set('database.connections', null);
        $this->expectException(InvalidConnectionException::class);
        app(ProtectorConfiguratorContract::class)->setConnectionName('invalid');
    }

    /**
     * @test
     */
    public function throwsExceptionOnFileNotFound()
    {
        $path = 'thisFileDoesNotExist';

        $this->expectException(FileNotFoundException::class);
        $this->protector->importDump($path);
    }

    /**
     * @test
     */
    public function throwsExceptionOnMysqlFailedShellCommand()
    {
        $connection = env('DB_CONNECTION');

        Config::set('database.default', $connection);
        Config::set(sprintf('database.connections.%s.host', $connection), 'protector.invalid');

        $this->expectException(FailedWipeException::class);
        $this->protector->importDump($this->filePath);

        // Without wiping the database, we expect the import to fail instead.
        $this->expectException(FailedImportException::class);
        $this->protector->importDump($this->filePath, ['no-wipe' => true]);
    }

    /**
     * @test
     * @dataProvider provideEmptyDumpsWhenReceivingTheLatestDumpName
     */
    public function canReturnLatestFileName(string $expectedFileName, bool $shouldModify)
    {
        if ($shouldModify) {
            touch($this->disk->path($expectedFileName), time() + 60);
        }

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($expectedFileName, $fileName);
        $this->assertIsString($fileName);
    }

    /**
     * @test
     */
    public function throwsExceptionIfNoFileExists()
    {
        $this->clearDumpDirectory();
        $this->expectException(EmptyBaseDirectoryException::class);
        $this->protector->getLatestDumpName();
    }

    /**
     * @test
     * @dataProvider provideDumpMetadata
     */
    public function verifyDumpDateMetadata($filePath, $expectedMetadata)
    {
        $this->assertEquals($expectedMetadata, $this->protector->getDumpMetadata($filePath));
    }

    /**
     * @test
     */
    public function failGetDumpMetadataOnResponseHasNotEnoughLines()
    {
        $this->assertEquals(false, $this->protector->getDumpMetadata(static::$baseDirectory . '/emptyDump.sql'));
    }

    /**
     * @test
     * @dataProvider provideEmptyDumpsForFlushingDumps
     */
    public function flushDumps($expected, $excludeFromFlush)
    {
        $this->protector->flush($excludeFromFlush);

        $dumpsAfterFlushing = $this->protector->getDumpFiles()->toArray();

        $this->assertEquals($expected, $dumpsAfterFlushing);
    }
}
