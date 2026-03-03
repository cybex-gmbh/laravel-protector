<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingPrivateKeyException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingServerUrlException;
use Cybex\Protector\Exceptions\InvalidConfiguration\NoAuthConfiguredException;
use Cybex\Protector\Exceptions\InvalidConfiguration\SanctumBasicAuthConflictException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RemoteDumpTest extends TestCase
{
    protected Filesystem $disk;

    protected string $serverUrl;
    protected string $baseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.remoteDump.serverUrl', 'protector.invalid/protector/exportDump');
        Config::set('protector.serverConfig.routeMiddleware', []);
        Config::set('protector.remoteDump.basicAuthCredentials', '1234:1234');

        $this->disk = Storage::disk('local');
        $this->serverUrl = app('protector')->getServerUrl();
        $this->baseDirectory = Config::get('protector.baseDirectory');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $format = sprintf('%s%s%s.sql', $this->baseDirectory, DIRECTORY_SEPARATOR, '%s');

        $path = sprintf($format, 'dump');
        $secondPath = sprintf($format, 'dumpWithGit');
        $thirdPath = sprintf($format, 'dumpWithoutMetadata');
        $fourthPath = sprintf($format, 'dumpWithIncorrectMetadata');
        $fifthPath = sprintf($format, 'dumpWithDifferentConnection');
        $sixthPath = sprintf($format, 'emptyDump');

        $files = $this->disk->files($this->baseDirectory);
        $files = array_diff($files, [$path, $secondPath, $thirdPath, $fourthPath, $fifthPath, $sixthPath]);

        $this->disk->delete($files);
    }

    /**
     * @test
     */
    public function sanctumIsEnabled()
    {
        Config::set('protector.serverConfig.routeMiddleware', ['auth:sanctum']);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertTrue($shouldEncrypt);
    }

    /**
     * @test
     */
    public function sanctumIsNotEnabled()
    {
        Config::set('protector.serverConfig.routeMiddleware', []);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertFalse($shouldEncrypt);
    }

    /**
     * @test
     */
    public function failOnMissingServerUrl()
    {
        Config::set('protector.remoteDump.serverUrl', '');

        $this->expectException(MissingServerUrlException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenPrivateKeyIsNotSet()
    {
        Config::set('protector.serverConfig.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.remoteDump.privateKey', '');

        $this->expectException(MissingPrivateKeyException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenResponseCouldNotBeReceived()
    {
        Config::set('protector.serverConfig.routeMiddleware', []);

        Http::fake([
            __FUNCTION__ => Http::response(),
        ]);

        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function basicAuthIsInRequestHeaderWhenSpecified()
    {
        Config::set('protector.remoteDump.basicAuthCredentials', '1234:1234');
        Config::set('protector.serverConfig.routeMiddleware', []);

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
        $message = env('PROTECTOR_DECRYPTED_MESSAGE');
        $encryptedMessage = sodium_hex2bin(env('PROTECTOR_ENCRYPTED_MESSAGE'));
        $publicKey = sodium_hex2bin(env('PROTECTOR_PUBLIC_KEY'));

        $chunkSize = strlen($message);
        $encryptionOverhead = $this->runProtectedMethod('determineEncryptionOverhead', [$chunkSize, $publicKey]);

        Http::fake([
            $this->serverUrl => Http::response($encryptedMessage, 200, [
                'Sanctum-Enabled' => true,
                'Content-Disposition' => sprintf('attachment; filename="%s.txt"', __FUNCTION__),
                'Chunk-Size' => strlen($message) + $encryptionOverhead,
            ]),
        ]);

        $destinationFilepath = $this->protector->getRemoteDump();

        $this->assertFileExists($this->disk->path($destinationFilepath));
        $this->assertEquals($message, $this->disk->get($destinationFilepath));
    }

    public function responseCodes()
    {
        return [
            'redirect' => [302],
            'server error' => [500],
        ];
    }

    /**
     * @test
     */
    public function failOnLaravelSanctumIsDisabledAndNoBasicAuthDefined()
    {
        Config::set('protector.serverConfig.routeMiddleware', []);
        Config::set('protector.remoteDump.basicAuthCredentials', '');

        $this->expectException(NoAuthConfiguredException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    /**
     * @test
     */
    public function failOnLaravelSanctumIsActiveAndBasicAuthIsDefined()
    {
        Config::set('protector.serverConfig.routeMiddleware', ['auth:sanctum']);

        $this->expectException(SanctumBasicAuthConflictException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    /**
     * @test
     */
    public function addAdditionalOptionsToRequest()
    {
        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue($options['stream']);
        $this->assertEquals('application/json', $options['headers']['Accept']);
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    /**
     * @test
     * When the Sanctum token is configured in the request, it will contain an option 'headers', which is an array containing the Authorization header.
     */
    public function addTokenToRequestWhenSanctumIsActive()
    {
        Config::set('protector.serverConfig.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.remoteDump.basicAuthCredentials', null);

        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue(isset($options['headers']['Authorization']));
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    /**
     * @test
     * When Basic Auth is configured in the request, it will contain an option 'auth', which is an array containing the credentials.
     */
    public function addBasicAuthToRequestWhenBasicAuthIsUsed()
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
        Config::set(sprintf('database.connections.%s.database', env('DB_CONNECTION')), __FUNCTION__);
        $this->protector->withConnectionName();

        $this->assertEquals(__FUNCTION__, $this->protector->getDatabaseName());
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
        $structure = '%s %d-%d-%d %d-%d %x.sql';
        config()->set('protector.fileName', $structure);

        $fileName = $this->protector->createFilename();

        $this->assertIsString($fileName);
        $this->assertStringMatchesFormat($structure, $fileName);
    }

    /**
     * @test
     */
    public function canGetAuthToken()
    {
        $authToken = $this->runProtectedMethod('getAuthToken');

        $this->assertEquals(config('protector.remoteDump.authToken'), $authToken);
    }

    /**
     * @test
     */
    public function setAuthToken()
    {
        $this->protector->withAuthToken(__FUNCTION__);

        $authToken = $this->runProtectedMethod('getAuthToken');

        $this->assertEquals(__FUNCTION__, $authToken);
    }

    /**
     * @test
     */
    public function canGetServerUrl()
    {
        $this->assertEquals(config('protector.remoteDump.serverUrl'), $this->protector->getServerUrl());
    }
}
