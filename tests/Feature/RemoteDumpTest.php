<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
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
use PHPUnit\Framework\Attributes\Test;
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

        $this->dumpEndpointUrl = 'protector.invalid/protector/exportDump';

        Config::set('protector.client.dumpEndpointUrl', $this->dumpEndpointUrl);
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '1234:1234');

        $this->disk = Storage::disk('local');
        $this->baseDirectory = Config::get('protector.dump.disks.storage.baseDirectory');
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

    #[Test]
    public function sanctumIsEnabled(): void
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);

        $shouldEncrypt = $this->runProtectedMethod('getConfig')->shouldEncrypt();

        $this->assertTrue($shouldEncrypt);
    }

    #[Test]
    public function sanctumIsNotEnabled(): void
    {
        Config::set('protector.server.routeMiddleware', []);

        $shouldEncrypt = $this->runProtectedMethod('getConfig')->shouldEncrypt();

        $this->assertFalse($shouldEncrypt);
    }

    #[Test]
    public function failOnMissingDumpEndpointUrl(): void
    {
        Config::set('protector.client.dumpEndpointUrl', '');

        $this->expectException(MissingDumpEndpointUrlException::class);
        $this->protector->download();
    }

    #[Test]
    public function failWhenPrivateKeyIsNotSet(): void
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.client.privateKey', '');

        $this->expectException(MissingPrivateKeyException::class);
        $this->protector->download();
    }

    #[Test]
    public function failWhenResponseCouldNotBeReceived(): void
    {
        Config::set('protector.server.routeMiddleware', []);

        Http::fake([
            __FUNCTION__ => Http::response(),
        ]);

        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->protector->download();
    }

    #[Test]
    public function basicAuthIsInRequestHeaderWhenSpecified(): void
    {
        Config::set('protector.client.basicAuthCredentials', '1234:1234');
        Config::set('protector.server.routeMiddleware', []);

        $dump = file_get_contents(__DIR__ . '/../dumps/dump.sql');

        Http::fake([
            $this->dumpEndpointUrl => Http::response($dump, 200, ['Chunk-Size' => 1024]),
        ]);

        $this->protector->download();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('1234:1234'));
        });
    }

    #[Test]
    public function emptyDumpThrowsException(): void
    {
        Http::fake([
            $this->dumpEndpointUrl => Http::response('', 200, ['Chunk-Size' => 100]),
        ]);

        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        $this->protector->download();
    }

    #[Test]
    public function throwUnauthorizedExceptionIfUnauthorized(): void
    {
        $statusCodes = [401, 403];

        foreach ($statusCodes as $statusCode) {
            $this->expectException(UnauthorizedHttpException::class);

            Http::fake([
                $this->dumpEndpointUrl => Http::response([], $statusCode),
            ]);

            $this->protector->download();
        }
    }

    #[Test]
    public function failOnUnknownRoute(): void
    {
        $this->expectException(NotFoundHttpException::class);

        Http::fake([
            $this->dumpEndpointUrl => Http::response([], 404),
        ]);

        $this->protector->download();
    }

    #[Test]
    public function failOnFetchingRemoteDump(): void
    {
        $this->expectException(FailedRemoteDatabaseFetchingException::class);

        Http::fake([
            $this->dumpEndpointUrl => Http::response([], 500),
        ]);

        $this->protector->download();
    }

    #[Test]
    public function checkForSuccessfulDecryption(): void
    {
        $message = file_get_contents(__DIR__ . '/../dumps/dump.sql');#
        $publicKey = env('PROTECTOR_PUBLIC_KEY');
        $encryptedMessage = app(CrypterContract::class)->encrypt($message, $publicKey);

        $chunkSize = strlen($message);
        $encryptionOverhead = $this->runProtectedMethod('determineEncryptionOverhead', [$chunkSize, $publicKey]);

        Http::fake([
            $this->dumpEndpointUrl => Http::response($encryptedMessage, 200, [
                'Sanctum-Enabled' => true,
                'Content-Disposition' => sprintf('attachment; filename="%s.txt"', __FUNCTION__),
                'Chunk-Size' => strlen($message) + $encryptionOverhead,
            ]),
        ]);

        $destinationFilepath = $this->protector->download();
        $metadataFilePath = $destinationFilepath . '.meta';
        $decodedMetadataFile = json_decode($this->disk->get($metadataFilePath), true);
        $parsedDumpMetadata = $this->protector->getDumpMetadata($destinationFilepath);

        $this->assertFileExists($this->disk->path($destinationFilepath));
        $this->assertFileExists($this->disk->path($metadataFilePath));
        $this->assertEquals($message, $this->disk->get($destinationFilepath));
        $this->assertIsArray($parsedDumpMetadata);
        $this->assertIsArray($decodedMetadataFile);
        $this->assertEquals($parsedDumpMetadata, $decodedMetadataFile);
    }

    #[Test]
    public function failOnDecryptedDumpWithoutParseableMetadata(): void
    {
        $payloadWithoutMetadata = 'CREATE TABLE `users` (`id` int);';
        $publicKey = env('PROTECTOR_PUBLIC_KEY');
        $encryptedPayload = app(CrypterContract::class)->encrypt($payloadWithoutMetadata, $publicKey);

        $chunkSize = strlen($payloadWithoutMetadata);
        $encryptionOverhead = $this->runProtectedMethod('determineEncryptionOverhead', [$chunkSize, $publicKey]);

        Http::fake([
            $this->dumpEndpointUrl => Http::response($encryptedPayload, 200, [
                'Sanctum-Enabled' => true,
                'Content-Disposition' => 'attachment; filename="invalid-metadata.sql"',
                'Chunk-Size' => $chunkSize + $encryptionOverhead,
            ]),
        ]);

        $expectedFilePath = sprintf('%s%s%s', $this->baseDirectory, DIRECTORY_SEPARATOR, 'invalid-metadata.sql');

        $this->expectException(FailedRemoteDatabaseFetchingException::class);
        $this->expectExceptionMessage('Could not fetch database from remote server. Retrieved incomplete decrypted dump metadata.');

        try {
            $this->protector->download();
        } finally {
            $this->assertFalse($this->disk->exists($expectedFilePath));
        }
    }

    #[Test]
    public function nonEncryptedDownloadDoesNotLeaveLocalStagingFiles(): void
    {
        Config::set('protector.server.routeMiddleware', []);

        $localDisk = Storage::disk($this->protector->getLocalDiskName());
        $localBaseDirectory = $this->protector->getLocalDiskBaseDirectory();
        $filesBeforeDownload = $localDisk->allFiles($localBaseDirectory);

        Http::fake([
            $this->dumpEndpointUrl => Http::response(file_get_contents(__DIR__ . '/../dumps/dump.sql'), 200, ['Chunk-Size' => 1024]),
        ]);

        $downloadedFilePath = $this->protector->download();
        $metadataFilePath = $downloadedFilePath . '.meta';
        $decodedMetadataFile = json_decode($this->disk->get($metadataFilePath), true);
        $parsedDumpMetadata = $this->protector->getDumpMetadata($downloadedFilePath);

        $this->assertEquals($filesBeforeDownload, $localDisk->allFiles($localBaseDirectory));
        $this->assertIsArray($parsedDumpMetadata);
        $this->assertIsArray($decodedMetadataFile);
        $this->assertEquals($parsedDumpMetadata, $decodedMetadataFile);
    }

    #[Test]
    public function failOnLaravelSanctumIsDisabledAndNoBasicAuthDefined(): void
    {
        Config::set('protector.server.routeMiddleware', []);
        Config::set('protector.client.basicAuthCredentials', '');

        $this->expectException(NoAuthConfiguredException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    #[Test]
    public function failOnLaravelSanctumIsActiveAndBasicAuthIsDefined(): void
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);

        $this->expectException(SanctumBasicAuthConflictException::class);
        $this->runProtectedMethod('getConfiguredHttpRequest');
    }

    #[Test]
    public function addAdditionalOptionsToRequest(): void
    {
        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue($options['stream']);
        $this->assertEquals('application/json', $options['headers']['Accept']);
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    #[Test]
    public function addTokenToRequestWhenSanctumIsActive(): void
    {
        Config::set('protector.server.routeMiddleware', ['auth:sanctum']);
        Config::set('protector.client.basicAuthCredentials');

        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue(isset($options['headers']['Authorization']));
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    #[Test]
    public function addBasicAuthToRequestWhenBasicAuthIsUsed(): void
    {
        $request = $this->runProtectedMethod('getConfiguredHttpRequest');
        $options = $request->getOptions();

        $this->assertTrue(isset($options['auth']));
        $this->assertInstanceOf(PendingRequest::class, $request);
    }

    #[Test]
    public function canReturnDatabaseName(): void
    {
        Config::set(sprintf('database.connections.%s.database', env('DB_CONNECTION')), __FUNCTION__);
        $this->protector = app(ProtectorConfiguratorContract::class)->setConnectionName(env('DB_CONNECTION'))->makeProtector();

        $this->assertEquals(__FUNCTION__, $this->runProtectedMethod('getConfig')->getDatabaseName());
    }

    #[Test]
    public function canGetConfigValueForKey(): void
    {
        Config::set('protector.dump.disks.storage.baseDirectory', __FUNCTION__);

        $result = $this->protector->getStorageDiskBaseDirectory();

        $this->assertEquals(__FUNCTION__, $result);
    }

    #[Test]
    public function canResolveBaseDirectoryFromClosure(): void
    {
        $functionName = __FUNCTION__;

        Config::set('protector.dump.disks.storage.baseDirectory', fn() => $functionName);

        $result = $this->protector->getStorageDiskBaseDirectory();

        $this->assertEquals($functionName, $result);
    }

    #[Test]
    public function failDecryptingOnInvalidString(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->protector->decryptString(base64_encode(__FUNCTION__));
    }

    #[Test]
    public function canCreateFilename(): void
    {
        $structure = '%s %d-%d-%d %d-%d %x.sql';
        config()->set('protector.dump.fileName', $structure);

        $fileName = $this->protector->createFilename();

        $this->assertIsString($fileName);
        $this->assertStringMatchesFormat($structure, $fileName);
    }

    #[Test]
    public function canGetAuthToken(): void
    {
        $authToken = $this->runProtectedMethod('getConfig')->getAuthToken();

        $this->assertEquals(config('protector.client.authToken'), $authToken);
    }

    #[Test]
    public function setAuthToken(): void
    {
        $this->protector = app(ProtectorConfiguratorContract::class)->setAuthToken(__FUNCTION__)->makeProtector();

        $authToken = $this->runProtectedMethod('getConfig')->getAuthToken();

        $this->assertEquals(__FUNCTION__, $authToken);
    }

    #[Test]
    public function canGetDumpEndpointUrl(): void
    {
        $this->assertEquals(config('protector.client.dumpEndpointUrl'), $this->runProtectedMethod('getConfig')->getDumpEndpointUrl());
    }
}
