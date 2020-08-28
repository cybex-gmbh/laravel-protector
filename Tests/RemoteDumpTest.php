<?php

namespace Cybex\Protector\Tests;

use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\ProtectorFacade as Protector;
use Cybex\Protector\ProtectorServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

class RemoteDumpTest extends TestCase
{
    protected function getPackageProviders($app)
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
        $path = $fakeStorage->path('dumps');
        $this->assertDirectoryNotExists($path);

        Config::set('protector.dumpPath', $path);

        Protector::getRemoteDump();

        $this->assertDirectoryExists($path);
    }

    /**
     * @test
     */
    public function failsOnMissingServerUrl()
    {
        Config::set('protector.remoteEndpoint.serverUrl', '');

        $this->expectException(InvalidConfigurationException::class);
        Protector::getRemoteDump();
    }

    /**
     * @test
     */
    public function htaccessIsInRequestHeaderWhenSpecified()
    {
        Config::set('protector.remoteEndpoint.htaccessLogin', '1234:1234');
        Config::set('protector.routeMiddleware', []);

        Http::fake();

        Protector::getRemoteDump();
        Http::assertSent(function($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('1234:1234'));
        });
    }

    /**
     * @test
     * @dataProvider responseCodes
     *
     * @param $code
     */
    public function failsIfResponseCodeNot200($code)
    {
        Http::fake(['cybex.test/*' => Http::response('', $code, [])]);

        $this->assertEquals([false, 'Unauthorized access', null], Protector::getRemoteDump(), 'Unexpected return.');
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
    public function authenticationTokenIsInHeaderWhenLaravelSanctumIsActive()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.protector_db_token', '1234');

        Http::fake();

        Protector::getRemoteDump();
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer 1234');
        });
    }

    /**
     * @test
     */
    public function failIfLaravelSanctumIsDisabledAndNoHtaccessIsDefined()
    {
        Config::set('protector.routeMiddleware', []);
        Config::set('protector.remoteEndpoint.htaccessLogin', '');

        Http::fake();

        $this->expectException(InvalidConfigurationException::class);
        Protector::getRemoteDump();
    }

    /**
     * @test
     */
    public function succeedWhenEverythingIsFine()
    {
        Config::set('protector.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.protector_db_token', '1234');

        Http::fake();

        $this->assertEquals(true, Protector::getRemoteDump()[0], 'Retrieving dump did not succeed');
    }
}
