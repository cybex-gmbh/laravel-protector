<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RemoteDumpTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ProtectorServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('protector.remoteEndpoint.serverUrl', 'cybex.test/protector/exportDump');
    }

    /**
     * @test
     */
    public function createsDumpPathDestinationIfNotExists()
    {
        $fakeStorage = Storage::fake('test');
        $path        = $fakeStorage->path('dumps');

        Storage::deleteDirectory($path);

        Config::set('protector.dumpPath', $path);

        $method = $this->getAccessibleReflectionMethod('createDirectory');
        $method->invokeArgs(app('protector'), [$path]);

        $this->assertDirectoryExists($path);
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
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

        $serverUrl = app('protector')->getServerUrl();

        Http::fake([
            $serverUrl => Http::response([], 200, ['Chunk-Size' => 100]),
        ]);

        app('protector')->getRemoteDump();
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('1234:1234'));
        });
    }

    /**
     * @test
     */
    public function throwUnauthorizedExceptionIfUnauthorized()
    {
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

        $serverUrl   = app('protector')->getServerUrl();
        $statusCodes = [
            401,
            403,
        ];

        foreach ($statusCodes as $statusCode) {
            $this->expectException(UnauthorizedHttpException::class);

            Http::fake([
                $serverUrl => Http::response([], $statusCode),
            ]);

            app('protector')->getRemoteDump();
        }
    }

    /**
     * @test
     */
    public function failOnUnknownRoute()
    {
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');

        $this->expectException(NotFoundHttpException::class);

        $serverUrl = app('protector')->getServerUrl();

        Http::fake([
            $serverUrl => Http::response([], 404),
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

        $serverUrl = app('protector')->getServerUrl();
        $disk      = app('protector')->getDisk();

        Http::fake([
            $serverUrl => Http::response($encryptedMessage, 200, [
                'Sanctum-Enabled'     => true,
                'Content-Disposition' => 'attachment; filename=HelloWorld.txt',
                'Chunk-Size'          => strlen($message) + 48,
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

        Http::fake();
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
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');

        Http::fake();

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
