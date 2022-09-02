<?php

use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RemoteDumpTest extends BaseTest
{
    protected Filesystem $disk;

    protected string $serverUrl;
    protected string $baseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.remoteEndpoint.serverUrl', 'protector.invalid/protector/exportDump');
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');

        $this->disk          = Storage::disk('local');
        $this->serverUrl     = app('protector')->getServerUrl();
        $this->baseDirectory = Config::get('protector.baseDirectory');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $format = sprintf('%s%s%s.sql', $this->baseDirectory, DIRECTORY_SEPARATOR, '%s');

        $path       = sprintf($format, 'dump');
        $secondPath = sprintf($format, 'dumpWithGit');
        $thirdPath  = sprintf($format, 'dumpWithoutMetadata');
        $fourthPath = sprintf($format, 'dumpWithIncorrectMetadata');
        $fifthPath  = sprintf($format, 'dumpWithDifferentConnection');
        $sixthPath  = sprintf($format, 'emptyDump');

        $files = $this->disk->files($this->baseDirectory);
        $files = array_diff($files, [$path, $secondPath, $thirdPath, $fourthPath, $fifthPath, $sixthPath]);

        $this->disk->delete($files);
    }

    /**
     * @test
     */
    public function sanctumIsEnabled()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertTrue($shouldEncrypt);
    }

    /**
     * @test
     */
    public function sanctumIsNotEnabled()
    {
        Config::set('protector.routeMiddleware', []);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertFalse($shouldEncrypt);
    }

    /**
     * @test
     */
    public function failOnMissingServerUrl()
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
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

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
    public function emptyDumpThrowsException()
    {
        Http::fake([
            $this->serverUrl => Http::response('', 200, ['Chunk-Size' => 100]),
        ]);

        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->protector->getRemoteDump();
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
    public function failOnFetchingRemoteDump()
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        Http::fake([
            $this->serverUrl => Http::response([], 500),
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
        $publicKey        = sodium_hex2bin(env('PROTECTOR_PUBLIC_KEY'));

        $chunkSize = strlen($message);
        $encryptionOverhead = $this->runProtectedMethod('determineEncryptionOverhead', [$chunkSize, $publicKey]);

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

        $this->expectException(InvalidConfigurationException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    /**
     * @test
     */
    public function failOnLaravelSanctumIsActiveAndHtaccessIsDefined()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $this->expectException(InvalidConfigurationException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    /**
     * @test
     */
    public function addAdditionalOptionsToRequest()
    {
        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertEquals(true, $options['stream']);
        $this->assertEquals('application/json', $options['headers']['Accept']);
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    /**
     * @test
     */
    public function addTokenToRequestWhenSanctumIsActive()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.remoteEndpoint.htaccessLogin', null);

        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue(isset($options['headers']['Authorization']));
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    /**
     * @test
     */
    public function addBasicAuthToRequestWhenHtaccessIsUsed()
    {
        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue(isset($options['auth']));
        $this->assertInstanceOf(PendingRequest::class, $request);
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
    public function failOnNoDatabaseConnectionIsSet()
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

        $result = $this->runProtectedMethod('getConfigValueForKey', ['baseDirectory']);

        $this->assertEquals(__FUNCTION__, $result);
    }

    /**
     * @test
     */
    public function canResolveBaseDirectoryFromClosure()
    {
        $functionName = __FUNCTION__;

        Config::set('protector.baseDirectory', fn() => $functionName);

        $result = $this->runProtectedMethod('getConfigValueForKey', ['baseDirectory']);

        $this->assertEquals($functionName, $result);
    }

    /**
     * Sets the env key name for the private key.
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
        $fileName  = $this->protector->createFilename();
        $structure = '%s %d-%d-%d %d-%d %x.sql';

        $this->assertIsString($fileName);
        $this->assertStringMatchesFormat($structure, $fileName);
    }

    /**
     * @test
     */
    public function canGetAuthToken()
    {
        $authToken = $this->runProtectedMethod('getAuthToken');

        $this->assertEquals(env('PROTECTOR_AUTH_TOKEN'), $authToken);
    }

    /**
     * @test
     */
    public function setAuthToken()
    {
        $this->runProtectedMethod('setAuthToken', [__FUNCTION__]);
        $authToken = $this->runProtectedMethod('getAuthToken');

        $this->assertEquals(__FUNCTION__, $authToken);
    }

    /**
     * @test
     */
    public function setAuthTokenKeyName()
    {
        $this->runProtectedMethod('setAuthTokenKeyName', [__FUNCTION__]);
        $authTokenKeyName = $this->runProtectedMethod('getAuthTokenKeyName');

        $this->assertEquals(__FUNCTION__, $authTokenKeyName);
    }

    /**
     * @test
     */
    public function canGetServerUrl()
    {
        $this->assertEquals(config('protector.remoteEndpoint.serverUrl'), $this->protector->getServerUrl());
    }
}
