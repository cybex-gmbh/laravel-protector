<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RemoteDumpTest extends BaseTest
{
    protected string $serverUrl;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.remoteEndpoint.serverUrl', 'example.com/protector/exportDump');
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');

        $this->serverUrl = app('protector')->getServerUrl();
    }

    /**
     * @test
     */
    public function canCreateDestinationDumpPath()
    {
        $method      = $this->getAccessibleReflectionMethod('createDirectory');
        $fakeStorage = Storage::fake('test');
        $path        = 'dumps';

        $method->invokeArgs(app('protector'), [$path, $fakeStorage]);

        $fakeStorage->assertExists($path);
    }

    /**
     * @test
     */
    public function failsOnMissingServerUrl()
    {
        Config::set('protector.remoteEndpoint.serverUrl', '');

        $this->expectException(InvalidConfigurationException::class);
        app('protector')->getRemoteDump();
    }

    /**
     * @test
     */
    public function htaccessIsInRequestHeaderWhenSpecified()
    {
        Http::fake([
            $this->serverUrl => Http::response('testDump', 200, ['Chunk-Size' => 100]),
        ]);

        app('protector')->getRemoteDump();

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

        app('protector')->getRemoteDump();
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

            app('protector')->getRemoteDump();
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

        app('protector')->getRemoteDump();
    }

    /**
     * @test
     */
    public function checkForSuccessfulDecryption()
    {
        $message          = env('PROTECTOR_DECRYPTED_MESSAGE');
        $encryptedMessage = sodium_hex2bin(env('PROTECTOR_ENCRYPTED_MESSAGE'));
        $publicKey        = sodium_hex2bin(env('PROTECTOR_PUBLIC_KEY'));
        $disk             = app('protector')->getDisk();

        $determineEncryptionOverhead = $this->getAccessibleReflectionMethod('determineEncryptionOverhead');

        $chunkSize          = strlen($message);
        $encryptionOverhead = $determineEncryptionOverhead->invoke(app('protector'), $chunkSize, $publicKey);

        Http::fake([
            $this->serverUrl => Http::response($encryptedMessage, 200, [
                'Sanctum-Enabled'     => true,
                'Content-Disposition' => 'attachment; filename="HelloWorld.txt"',
                'Chunk-Size'          => strlen($message) + $encryptionOverhead,
            ]),
        ]);

        $destinationFilepath = app('protector')->getRemoteDump();

        $this->assertFileExists($disk->path($destinationFilepath));
        $this->assertEquals($message, $disk->get($destinationFilepath));
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
    public function failIfLaravelSanctumIsDisabledAndNoHtaccessIsDefined()
    {
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '');

        $method = $this->getAccessibleReflectionMethod('getConfiguredHttpRequest');

        $this->expectException(InvalidConfigurationException::class);
        $method->invoke(app('protector'));
    }

    /**
     * @test
     */
    public function failIfLaravelSanctumIsActiveAndHtaccessIsDefined()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);

        $method = $this->getAccessibleReflectionMethod('getConfiguredHttpRequest');

        $this->expectException(InvalidConfigurationException::class);
        $method->invoke(app('protector'));
    }

    /**
     * @param $method
     *
     * @return ReflectionMethod
     */
    protected function getAccessibleReflectionMethod($method): ReflectionMethod
    {
        $reflectionProtector = new ReflectionClass(app('protector'));
        $method              = $reflectionProtector->getMethod($method);

        $method->setAccessible(true);

        return $method;
    }
}
