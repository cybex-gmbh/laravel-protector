<?php

use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RemoteDumpTest extends BaseTest
{
    /**
     *  Protector instance.
     */
    protected Protector $protector;

    protected string $serverUrl;
    protected string $baseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.remoteEndpoint.serverUrl', 'protector.invalid/protector/exportDump');
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');

        $this->protector     = app('protector');
        $this->disk          = Storage::disk('local');
        $this->serverUrl     = app('protector')->getServerUrl();
        $this->baseDirectory = Config::get('protector.baseDirectory');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = $this->disk->files($this->baseDirectory);
        $files = array_diff($files, [sprintf('%s%sdump.sql', $this->baseDirectory, DIRECTORY_SEPARATOR)]);

        $this->disk->delete($files);
    }

    /**
     * @test
     */
    public function sanctumIsEnabled()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $method        = $this->getAccessibleReflectionMethod('shouldEncrypt');
        $shouldEncrypt = $method->invoke($this->protector);

        $this->assertTrue($shouldEncrypt);
    }

    /**
     * @test
     */
    public function sanctumIsNotEnabled()
    {
        Config::set('protector.routeMiddleware', []);

        $method        = $this->getAccessibleReflectionMethod('shouldEncrypt');
        $shouldEncrypt = $method->invoke($this->protector);

        $this->assertFalse($shouldEncrypt);
    }

    /**
     * @test
     */
    public function failsOnMissingServerUrl()
    {
        Config::set('protector.remoteEndpoint.serverUrl', '');

        $this->expectException(InvalidConfigurationException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failOnProductionEnvironment()
    {
        $this->app->detectEnvironment(fn() => 'production');

        Http::fake([
            $this->serverUrl => Http::response(),
        ]);

        $this->expectException(InvalidEnvironmentException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenPrivateKeyIsNotSet()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $this->protector->setPrivateKeyName('');

        Http::fake([
            $this->serverUrl => Http::response(),
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenResponseCouldNotBeReceived()
    {
        Config::set('protector.routeMiddleware', []);

        Http::fake([
            __FUNCTION__ => Http::response(),
        ]);

        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function htaccessIsInRequestHeaderWhenSpecified()
    {
        Http::fake([
            $this->serverUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
        ]);

        $this->protector->getRemoteDump();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('1234:1234'));
        });
    }

    /**
     * @test
     */
    public function throwUnauthorizedExceptionIfUnauthorized()
    {
        $statusCodes = [
            401,
            403,
        ];

        foreach ($statusCodes as $statusCode) {
            $this->expectException(UnauthorizedHttpException::class);

            Http::fake([
                $this->serverUrl => Http::response([], $statusCode),
            ]);

            $this->protector->getRemoteDump();
        }
    }

    /**
     * @test
     */
    public function failOnUnknownRoute()
    {
        $this->expectException(NotFoundHttpException::class);

        Http::fake([
            $this->serverUrl => Http::response([], 404),
        ]);

        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function checkForSuccessfulDecryption()
    {
        $message          = env('PROTECTOR_DECRYPTED_MESSAGE');
        $encryptedMessage = sodium_hex2bin(env('PROTECTOR_ENCRYPTED_MESSAGE'));
        $publicKey        = env('PROTECTOR_PUBLIC_KEY');

        $determineEncryptionOverhead = $this->getAccessibleReflectionMethod('determineEncryptionOverhead');

        $chunkSize = strlen($message);
        $encryptionOverhead = $determineEncryptionOverhead->invoke($this->protector, $chunkSize, $publicKey);

        Http::fake([
            $this->serverUrl => Http::response($encryptedMessage, 200, [
                'Sanctum-Enabled'     => true,
                'Content-Disposition' => sprintf('attachment; filename="%s.txt"', __FUNCTION__),
                'Chunk-Size'          => strlen($message) + $encryptionOverhead,
            ]),
        ]);

        $destinationFilepath = $this->protector->getRemoteDump();

        $this->assertFileExists($this->disk->path($destinationFilepath));
        $this->assertEquals($message, $this->disk->get($destinationFilepath));
    }

    public function responseCodes()
    {
        return [
            'redirect'     => [302],
            'server error' => [500],
        ];
    }

    /**
     * @test
     */
    public function failOnLaravelSanctumIsDisabledAndNoHtaccessDefined()
    {
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '');

        $method = $this->getAccessibleReflectionMethod('getConfiguredHttpRequest');

        $this->expectException(InvalidConfigurationException::class);
        $method->invoke($this->protector);
    }

    /**
     * @test
     */
    public function failIfLaravelSanctumIsActiveAndHtaccessIsDefined()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $method = $this->getAccessibleReflectionMethod('getConfiguredHttpRequest');

        $this->expectException(InvalidConfigurationException::class);
        $method->invoke($this->protector);
    }

    /**
     * @test
     */
    public function addTokenToRequestWhenSanctumIsActive()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.remoteEndpoint.htaccessLogin', null);

        $method = $this->getAccessibleReflectionMethod('getConfiguredHttpRequest');
        $result = $method->invoke($this->protector);

        $this->assertIsObject($result, 'Not readable');
    }

    /**
     * @test
     */
    public function canReturnDatabaseName()
    {
        Config::set('database.connections.mysql.database', __FUNCTION__);
        $this->protector->configure();

        $this->assertEquals(__FUNCTION__, $this->protector->getDatabaseName());
    }

    /**
     * @test
     */
    public function failIfNoDatabaseConnectionIsSet()
    {
        Config::set('database.connections', null);

        $result = $this->protector->configure();

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function canGetConfigValueForKey()
    {
        Config::set('protector.baseDirectory', __FUNCTION__);

        $method = $this->getAccessibleReflectionMethod('getConfigValueForKey');
        $result = $method->invoke($this->protector, 'baseDirectory');

        $this->assertEquals(__FUNCTION__, $result);
    }

    /**
     * @test
     */
    public function canResolveBaseDirectoryFromClosure()
    {
        $method = $this->getAccessibleReflectionMethod('getConfigValueForKey');

        Config::set('protector.baseDirectory', fn() => 'null');
        $result = $method->invoke($this->protector, 'baseDirectory');

        $this->assertEquals('null', $result);
    }

    /**
     * @test
     */
    public function validateUsersPrivateKeyName()
    {
        $this->protector->setPrivateKeyName(__FUNCTION__);
        $privateKeyName = $this->protector->getPrivateKeyName();

        $this->assertEquals(__FUNCTION__, $privateKeyName);
    }

    /**
     * @test
     */
    public function failDecryptingOnInvalidString()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->protector->decryptString(base64_encode(__FUNCTION__));
    }

    /**
     * @test
     */
    public function canCreateFilename()
    {
        $fileName = $this->protector->createFilename();

        $this->assertIsString($fileName);
    }

    /**
     * @test
     */
    public function canGetAuthToken()
    {
        $methodGet = $this->getAccessibleReflectionMethod('getAuthToken');
        $authToken = $methodGet->invoke($this->protector);

        $this->assertEquals(env('PROTECTOR_AUTH_TOKEN'), $authToken);
    }

    /**
     * @test
     */
    public function setAuthToken()
    {
        $methodSet = $this->getAccessibleReflectionMethod('setAuthToken');
        $methodGet = $this->getAccessibleReflectionMethod('getAuthToken');

        $methodSet->invoke($this->protector, __FUNCTION__);
        $authToken = $methodGet->invoke($this->protector);

        $this->assertEquals(__FUNCTION__, $authToken);
    }

    /**
     * @test
     */
    public function setAuthTokenKeyName()
    {
        $methodSet = $this->getAccessibleReflectionMethod('setAuthTokenKeyName');
        $methodGet = $this->getAccessibleReflectionMethod('getAuthTokenKeyName');

        $methodSet->invoke($this->protector, __FUNCTION__);
        $authTokenKeyName = $methodGet->invoke($this->protector);

        $this->assertEquals(__FUNCTION__, $authTokenKeyName);
    }

    /**
     * @test
     */
    public function canGetServerUrl()
    {
        $this->assertEquals(config('protector.remoteEndpoint.serverUrl'), $this->protector->getServerUrl());
    }

    /**
     * @test
     */
    public function setServerUrl()
    {
        Config::set('protector.remoteEndpoint.serverUrl', __FUNCTION__);
        $this->assertEquals(__FUNCTION__, $this->protector->getServerUrl());
    }

    /**
     * @param $method
     *
     * @return ReflectionMethod
     */
    protected function getAccessibleReflectionMethod($method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass($this->protector);
        $method              = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }
}
