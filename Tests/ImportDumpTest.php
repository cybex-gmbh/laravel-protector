<?php

use Cybex\Protector\Exceptions\FailedShellCommandException;
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

        $this->expectException(FailedShellCommandException::class);
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
     */
    public function verifyDumpDateMetaData()
    {
        $dumpMetaData = $this->protector->getDumpMetaData($this->filePath);
        $date         = $dumpMetaData['meta']['dumpedAtDate'];
        $result       = checkDate($date['mon'], $date['wday'], $date['year']);

        $dateKeys     = ['seconds', 'minutes', 'hours', 'mday', 'wday', 'mon', 'year', 'yday', 'weekday', 'month'];

        foreach ($dateKeys as $key)
        {
            $this->assertTrue(isset($date[$key]));
        }

        $this->assertTrue($result);
        $this->assertIsArray($dumpMetaData);
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function failOnDumpHasNoMetaData()
    {
        $this->assertFalse($this->protector->getDumpMetaData($this->emptyDumpPath));
    }

    /**
     * @test
     * @define-env usesEmptyDump
     */
    public function failOnDumpHasIncorrectMetaData()
    {
        $this->disk->put($this->emptyDumpPath, sprintf("%s\n%s", __FUNCTION__, __FUNCTION__));

        $metaData = sprintf("-- options:%s\n-- meta:%s", __FUNCTION__, __FUNCTION__);
        $this->disk->append($this->emptyDumpPath, $metaData);

        $this->assertFalse($this->protector->getDumpMetaData($this->emptyDumpPath));
    }
}
