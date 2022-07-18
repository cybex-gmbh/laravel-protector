<?php

use Cybex\Protector\Exceptions\FailedMysqlCommandException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class ImportDumpTest extends BaseTest
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    protected string $baseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.baseDirectory', 'protector');

        $this->protector     = app('protector');
        $this->disk          = Storage::disk('local');
        $this->baseDirectory = Config::get('protector.baseDirectory');
        $this->filePath      = sprintf('%s%sdump.sql', $this->baseDirectory, DIRECTORY_SEPARATOR);
        $this->emptyDumpPath = 'dynamicDumps/dump.sql';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Config::set('protector.baseDirectory', 'dynamicDumps');
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));
    }

    public function provideDumpMetadata(): array
    {
        return [
            [
                "protector/dump.sql",
                [
                    'meta' => [
                        'database'        => 'protector-tests',
                        'connection'      => 'mysql',
                        'gitRevision'     => '',
                        'gitBranch'       => '',
                        'gitRevisionDate' => '',
                        'dumpedAtDate'    => [
                            'seconds' => 24,
                            'minutes' => 43,
                            'hours'   => 12,
                            'mday'    => 29,
                            'wday'    => 3,
                            'mon'     => 6,
                            'year'    => 2022,
                            'yday'    => 179,
                            'weekday' => 'Wednesday',
                            'month'   => 'June',
                            0         => 1656506604
                        ]
                    ]
                ]
            ],
            [
                "protector/dumpWithGit.sql",
                [
                    'meta' => [
                        'database'        => 'protector-tests',
                        'connection'      => 'mysql',
                        'gitRevision'     => '2aae6c35c94fcfb415dbe95f408b9ce91ee846ed',
                        'gitBranch'       => 'feature/tests',
                        'gitRevisionDate' => '2022-07-12 08:00:00 +0200',
                        'dumpedAtDate'    => [
                            'seconds' => 24,
                            'minutes' => 43,
                            'hours'   => 12,
                            'mday'    => 29,
                            'wday'    => 3,
                            'mon'     => 6,
                            'year'    => 2022,
                            'yday'    => 179,
                            'weekday' => 'Wednesday',
                            'month'   => 'June',
                            0         => 1656506604
                        ]
                    ]
                ]
            ],
            [
                "protector/dumpWithoutMetadata.sql",
                []
            ],
            [
                "protector/dumpWithIncorrectMetadata.sql",
                false
            ],
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
        $this->protector->configure();

        $this->expectException(InvalidConnectionException::class);
        $this->protector->importDump($this->filePath);
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

        $this->protector->configure();

        $this->expectException(FailedMysqlCommandException::class);
        $this->protector->importDump($this->filePath);
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function returnsFileNameIfExists()
    {
        Config::set('protector.baseDirectory', 'dynamicDumps');

        $filePath = sprintf('%s%sdump.sql', Config::get('protector.baseDirectory'), DIRECTORY_SEPARATOR);

        touch($this->disk->path($filePath));

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($filePath, $fileName);
        $this->assertIsString($fileName);
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function returnsFileNameIfMultipleDumpsExist()
    {
        Config::set('protector.baseDirectory', 'dynamicDumps');

        $secondDumpFilePath = sprintf('%s%ssecondDump.sql', Config::get('protector.baseDirectory'), DIRECTORY_SEPARATOR);

        $this->disk->put($secondDumpFilePath, '');
        touch($this->disk->path($secondDumpFilePath), time() + 60);

        $fileName = $this->protector->getLatestDumpName();

        $this->assertEquals($secondDumpFilePath, $fileName);
        $this->assertIsString($fileName);
    }

    /**
     * @test
     * @define-env usesEmptyFolder
     */
    public function throwsExceptionIfNoFileExists()
    {
        Config::set('protector.baseDirectory', 'noDumps');
        $this->disk->deleteDirectory(Config::get('protector.baseDirectory'));

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
     * @return void
     * @define-env usesEmptyDump
     */
    public function failGetDumpMetaDataOnResponseHasNotEnoughLines(): void
    {
        $this->assertEquals(false, $this->protector->getDumpMetaData($this->emptyDumpPath));
    }
}
