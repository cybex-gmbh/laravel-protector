<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingDumpEndpointUrlException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingPrivateKeyException;
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

    protected string $dumpEndpointUrl;
    protected string $baseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.client.dumpEndpointUrl', 'protector.invalid/protector/exportDump');
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '1234:1234');

        $this->disk = Storage::disk('local');
        $this->dumpEndpointUrl = app('protector')->getDumpEndpointUrl();
        $this->baseDirectory = Config::get('protector.dump.baseDirectory');
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
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertTrue($shouldEncrypt);
    }

    /**
     * @test
     */
    public function sanctumIsNotEnabled()
    {
        Config::set('protector.server.routeMiddleware', []);

        $shouldEncrypt = $this->runProtectedMethod('shouldEncrypt');

        $this->assertFalse($shouldEncrypt);
    }

    /**
     * @test
     */
    public function failOnMissingDumpEndpointUrl()
    {
        Config::set('protector.client.dumpEndpointUrl', '');

        $this->expectException(MissingDumpEndpointUrlException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenPrivateKeyIsNotSet()
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.client.privateKey', '');

        $this->expectException(MissingPrivateKeyException::class);
        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function failWhenResponseCouldNotBeReceived()
    {
        Config::set('protector.server.routeMiddleware', []);

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
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.server.routeMiddleware', []);

        Http::fake([
            $this->dumpEndpointUrl => Http::response(__FUNCTION__, 200, ['Chunk-Size' => 100]),
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
            $this->dumpEndpointUrl => Http::response('', 200, ['Chunk-Size' => 100]),
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
                $this->dumpEndpointUrl => Http::response([], $statusCode),
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
            $this->dumpEndpointUrl => Http::response([], 404),
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
            $this->dumpEndpointUrl => Http::response([], 500),
        ]);

        $this->protector->getRemoteDump();
    }

    /**
     * @test
     */
    public function checkForSuccessfulDecryption()
    {
        $message = env('PROTECTOR_DECRYPTED_MESSAGE');
        $encryptedMessage = base64_decode(env('PROTECTOR_ENCRYPTED_MESSAGE_BASE64'));
        $publicKey = env('PROTECTOR_PUBLIC_KEY');

        $chunkSize = strlen($message);
        $encryptionOverhead = $this->runProtectedMethod('determineEncryptionOverhead', [$chunkSize, $publicKey]);

        Http::fake([
            $this->dumpEndpointUrl => Http::response($encryptedMessage, 200, [
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
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '');

        $this->expectException(NoAuthConfiguredException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    /**
     * @test
     */
    public function failOnLaravelSanctumIsActiveAndBasicAuthIsDefined()
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);

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
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.client.basicAuthCredentials', null);

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
        Config::set('protector.dump.baseDirectory', __FUNCTION__);

        $result = $this->runProtectedMethod('getConfigValueForKey', ['dump.baseDirectory']);

        $this->assertEquals(__FUNCTION__, $result);
    }

    /**
     * @test
     */
    public function canResolveBaseDirectoryFromClosure()
    {
        $functionName = __FUNCTION__;

        Config::set('protector.dump.baseDirectory', fn() => $functionName);

        $result = $this->runProtectedMethod('getConfigValueForKey', ['dump.baseDirectory']);

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
        config()->set('protector.dump.fileName', $structure);

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

        $this->assertEquals(config('protector.client.authToken'), $authToken);
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
    public function canGetDumpEndpointUrl()
    {
        $this->assertEquals(config('protector.client.dumpEndpointUrl'), $this->protector->getDumpEndpointUrl());
    }
}
