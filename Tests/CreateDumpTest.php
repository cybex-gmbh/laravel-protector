<?php

use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Protector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class CreateDumpTest extends BaseTest
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
    public function createDestinationFilePath()
    {
        $this->disk->deleteDirectory($this->baseDirectory);

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__);
        $method   = $this->getAccessibleReflectionMethod('createDirectory');

        $method->invoke($this->protector, $this->disk->path($filePath));

        $this->assertDirectoryExists($filePath);
    }

    /**
     * @test
     */
    public function createDestinationFilePathWithSubFolder()
    {
        $this->disk->deleteDirectory($this->baseDirectory);

        $filePath = $this->protector->createDestinationFilePath(__FUNCTION__, __FUNCTION__);
        $method   = $this->getAccessibleReflectionMethod('createDirectory');

        $method->invoke($this->protector, $this->disk->path($filePath));

        $this->assertDirectoryExists($filePath);
    }

    /**
     * @test
     */
    public function failDirectoryCreationOnInvalidPath()
    {
        $method = $this->getAccessibleReflectionMethod('createDirectory');
        $path   = 'https://example.com/protector/exportDump';

        $this->expectException(FailedCreatingDestinationPathException::class);
        $method->invoke($this->protector, $path);
    }


    /**
     * @test
     */
    public function createMetaData()
    {
        $method = $this->getAccessibleReflectionMethod('getMetaData');

        $method->invoke($this->protector);
        $metaData = $method->invoke($this->protector, false);

        $this->assertIsArray($metaData);
    }

    /**
     * @test
     */
    public function verifyDumpDateMetaData()
    {
        $method = $this->getAccessibleReflectionMethod('generateDump');

        $method->invoke($this->protector, $this->filePath, []);

        $dumpMetaData = $this->protector->getDumpMetaData($this->filePath);

        $date = $dumpMetaData['meta']['dumpedAtDate'];
        $result = checkDate($date['mon'], $date['wday'], $date['year']);

        foreach (array_keys($date) as $key)
        {
            if (!isset($date[$key]))
            {
                $result = false;
            }
        }

        $this->assertTrue($result);
        $this->assertIsArray($dumpMetaData);
    }

    /**
     * @test
     */
    public function failOnDumpHasNoMetaData()
    {
        $metaData = $this->protector->getDumpMetaData($this->filePath);

        $this->assertFalse($metaData);
    }

    /**
     * @test
     */
    public function failOnDumpHasIncorrectMetaData()
    {
        $this->disk->put($this->filePath, sprintf("%s\n%s", __FUNCTION__, __FUNCTION__));

        $metaData = sprintf("-- options:%s\n-- meta:%s", __FUNCTION__, __FUNCTION__);
        $this->disk->append($this->filePath, $metaData);

        $result = $this->protector->getDumpMetaData($this->filePath);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function canGenerateDump()
    {
        $this->disk->put($this->filePath, __FUNCTION__);

        $method = $this->getAccessibleReflectionMethod('generateDump');
        $result = $method->invoke($this->protector, $this->filePath, ['no-data' => true]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function failGeneratingDumpOnInvalidPath()
    {
        $path   = 'https://www.example.com';
        $method = $this->getAccessibleReflectionMethod('generateDump');

        $this->expectException(ErrorException::class);
        $result = $method->invoke($this->protector, $path, []);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function failOnDumpHasNoConnectionConfigured()
    {
        Config::set('database.connections', null);
        $this->protector->configure();

        $this->expectException(InvalidConnectionException::class);
        $this->protector->createDump(__FUNCTION__, []);
    }

    /**
     * @test
     */
    public function canReturnDestinationFilePath()
    {
        $destinationFilePath = $this->protector->createDump(__FUNCTION__, []);

        $this->assertEquals(sprintf('%s/%s', $this->baseDirectory, __FUNCTION__), $destinationFilePath);
        $this->assertIsString($destinationFilePath);

        $this->disk->delete($destinationFilePath);
    }

    /**
     * @param $method
     *
     * @return ReflectionMethod
     */
    protected function getAccessibleReflectionMethod($method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass($this->protector);
        $method = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }

}
