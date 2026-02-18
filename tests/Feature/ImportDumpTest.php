<?php

namespace Cybex\Protector\Tests\Feature;

use Carbon\Carbon;
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
    protected Filesystem $disk;
    protected static string $baseDirectory = 'dumps';
    protected string $filePath;
    protected Protector $protector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protector = app('protector');

        Config::set('protector.baseDirectory', static::$baseDirectory);

        $this->disk = $this->getFakeDumpDisk();

        $this->filePath = getcwd() . '/tests/dumps/dump.sql';
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
                            'dumpedAtDate' => Carbon::parse('2022-06-29 12:43:24')->toDateTimeString(),
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
                            'dumpedAtDate' => Carbon::parse('2022-06-29 12:43:24')->toDateTimeString(),
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
        $this->protector->withConnectionName(null);
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
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', 'protector.invalid');

        $this->protector->withConnectionName(null);

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
        $this->expectException(FileNotFoundException::class);
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

        $baseDirectory = $this->protector->getBaseDirectory();
        $dumpsAfterFlushing = $this->disk->files($baseDirectory);

        $this->assertEquals($expected, $dumpsAfterFlushing);
    }
}
