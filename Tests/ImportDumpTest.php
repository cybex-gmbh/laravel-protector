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
        $this->filePath      = sprintf('%s/dump.sql', $this->baseDirectory);
    }

    /**
     * @test
     */
    public function failOnProductionEnvironment()
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->expectException(InvalidEnvironmentException::class);
        $this->protector->importDump($this->filePath, ['allow-production' => false]);
    }

    /**
     * @test
     */
    public function failOnInvalidConnectionConfig()
    {
        Config::set('database.connections', null);
        $this->protector->configure();

        $this->expectException(InvalidConnectionException::class);
        $this->protector->importDump($this->filePath, []);
    }

    /**
     * @test
     */
    public function throwsExceptionOnFileNotFound()
    {
        $path = tempnam('', 'protector');

        $this->expectException(FileNotFoundException::class);
        $this->protector->importDump($path, []);
    }

    /**
     * @test
     */
    public function throwsExceptionOnMysqlFailedShellCommand()
    {
        $this->expectException(FailedShellCommandException::class);
        $this->protector->importDump($this->filePath, ['migrate' => false]);
    }
}
