<?php

namespace Cybex\Protector\Tests\feature;

use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedMysqlCommandException;
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
                        'database' => 'protector-tests',
                        'connection' => 'mysql',
                        'gitRevision' => '',
                        'gitBranch' => '',
                        'gitRevisionDate' => '',
                        'dumpedAtDate' => [
                            'seconds' => 24,
                            'minutes' => 43,
                            'hours' => 12,
                            'mday' => 29,
                            'wday' => 3,
                            'mon' => 6,
                            'year' => 2022,
                            'yday' => 179,
                            'weekday' => 'Wednesday',
                            'month' => 'June',
                            0 => 1656506604
                        ]
                    ]
                ]
            ],
            [
                static::$baseDirectory . "/dumpWithGit.sql",
                [
                    'meta' => [
                        'database' => 'protector-tests',
                        'connection' => 'mysql',
                        'gitRevision' => '2aae6c35c94fcfb415dbe95f408b9ce91ee846ed',
                        'gitBranch' => 'feature/tests',
                        'gitRevisionDate' => '2022-07-12 08:00:00 +0200',
                        'dumpedAtDate' => [
                            'seconds' => 24,
                            'minutes' => 43,
                            'hours' => 12,
                            'mday' => 29,
                            'wday' => 3,
                            'mon' => 6,
                            'year' => 2022,
                            'yday' => 179,
                            'weekday' => 'Wednesday',
                            'month' => 'June',
                            0 => 1656506604
                        ]
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

        $this->expectException(FailedImportException::class);
        $this->protector->importDump($this->filePath);
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
    public function verifyDumpDateMetaData($filePath, $expectedMetaData)
    {
        $this->assertEquals($expectedMetaData, $this->protector->getDumpMetaData($filePath));
    }

    /**
     * @test
     */
    public function failGetDumpMetaDataOnResponseHasNotEnoughLines()
    {
        $this->assertEquals(false, $this->protector->getDumpMetaData(static::$baseDirectory . '/emptyDump.sql'));
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
