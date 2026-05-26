<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\MetadataHandler;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingDumpEndpointUrlException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingPrivateKeyException;
use Cybex\Protector\Exceptions\InvalidConfiguration\NoAuthConfiguredException;
use Cybex\Protector\Exceptions\InvalidConfiguration\SanctumBasicAuthConflictException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as DownloadResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Protector
{
    protected array $requiredFunctionsCache;

    public function __construct(
        protected ProtectorConfigContract $config,
        protected DiskHelperContract $diskHelper,
    )
    {
    }

    /**
     * Returns a new Protector instance with the given configuration.
     */
    public static function withConfig(ProtectorConfigContract $config): static
    {
        return app()->makeWith('protector', ['config' => $config]);
    }

    /**
     * Imports a specific SQL dump.
     * The source file path can either be a local absolute path, or a relative path on the passed disk (defaults to the storage disk).
     *
     * @param string $filePath Either a local absolute path or a relative path on the specified disk.
     * @param Filesystem|null $disk Defaults to the Protector storage disk.
     * @param bool|null $noWipe Whether the database should not be wiped before import.
     * @param bool|null $migrate Whether to run migrations after import.
     * @param bool|null $allowProduction Allow importing in the production enviroment.
     *
     * @return void
     *
     * @throws FailedImportException
     * @throws FailedWipeException
     * @throws FileNotFoundException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     */
    public function import(
        string $filePath,
        ?Filesystem $disk = null,
        ?bool $noWipe = false,
        ?bool $migrate = false,
        ?bool $allowProduction = false,
    ): void
    {
        $this->guardRequiredFunctionsEnabled();

        // Production environment is not allowed unless set in options.
        if (App::environment('production') && !$allowProduction) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        $absoluteImportFilePath = $filePath;

        if (!$this->diskHelper->isAbsolutePath($filePath)) {
            $localFilePath = $this->diskHelper->copyStorageToLocal(
                $filePath,
                $disk,
            );

            $absoluteImportFilePath = $this->diskHelper->getLocalDisk()->path($localFilePath);
        }

        if (!file_exists($absoluteImportFilePath)) {
            throw new FileNotFoundException($absoluteImportFilePath);
        }

        if (!$noWipe) {
            try {
                $this->wipeDatabase(DB::connection($this->config->getConnectionName()));
            } catch (Throwable $exception) {
                throw new FailedWipeException($exception->getMessage());
            }
        }

        try {
            $this->getSchemaStateProxy()->load($absoluteImportFilePath);
        } catch (Throwable $exception) {
            throw new FailedImportException($exception->getMessage());
        } finally {
            if (!$this->diskHelper->isAbsolutePath($filePath)) {
                $this->diskHelper->deleteLocalFiles($localFilePath);
            }
        }

        if ($migrate) {
            $output = new BufferedOutput;

            Artisan::call('migrate', [], $output);

            if (app()->runningInConsole()) {
                echo $output->fetch();
            }
        }
    }

    /**
     * Generates a dump from the current app database and saves it to the specified disk (defaults to the storage disk).
     *
     * @param string|null $filePath Optional file path on the disk to which the dump is written.
     * @param Filesystem|null $disk Defaults to the Protector storage disk.
     *
     * @return string The dump file path on the disk.
     *
     * @throws FailedDumpGenerationException
     * @throws InvalidConnectionException
     */
    public function export(?string $filePath = null, ?Filesystem $disk = null): string
    {
        $this->guardRequiredFunctionsEnabled();

        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        $destinationFilePath = $this->diskHelper->storagePath($filePath ?? $this->createFilename());
        $metadata = $this->metadata();

        $localDumpFile = $this->generateDump($metadata) ?: throw new FailedDumpGenerationException('Dump could not be created.');

        try {
            $this->diskHelper->copyLocalFileToDisk(
                localFilePath: $localDumpFile,
                destinationFilePath: $destinationFilePath,
                disk: $disk,
            );

            $this->diskHelper->writeMetadataFile($disk, $destinationFilePath, $metadata);

            return $destinationFilePath;
        } finally {
            $this->diskHelper->deleteLocalFiles($localDumpFile);
        }
    }

    /**
     * Downloads a dump file from a remote system and stores it on the specified disk (defaults to the storage disk).
     *
     * @param string|null $filePath Optional file path on the disk to which the dump is written.
     * @param Filesystem|null $disk Defaults to the storage disk.
     *
     * @return string The dump file path on the disk.
     *
     * @throws FailedRemoteDatabaseFetchingException
     */
    public function download(?string $filePath = null, ?Filesystem $disk = null): string
    {
        $this->guardDownload();

        // Telescope is interfering with the request / response.
        $telescopeWasRecording = $this->stopTelescopeRecording();

        $request = $this->getConfiguredHttpRequest();

        try {
            $response = $request->withoutRedirecting()->post($this->config->getDumpEndpointUrl());
        } catch (Exception $exception) {
            throw new FailedRemoteDatabaseFetchingException($exception->getMessage());
        } finally {
            $this->startTelescopeRecording($telescopeWasRecording);
        }

        if (!$response->ok()) {
            $this->handleDownloadResponseError($response);
        }

        $destinationFilePath = $filePath
            ?? $this->diskHelper->getDownloadDestinationFilePath($response->header('Content-Disposition'));

        $stream = $response->toPsrResponse()->getBody();
        $localFilePath = $this->diskHelper->localPath();

        try {
            $this->diskHelper->writeStreamToLocalFile(
                stream: $stream,
                destinationFilePath: $localFilePath,
                chunkSize: $response->header('Chunk-Size'),
                shouldEncrypt: $response->header('Sanctum-Enabled'),
                privateKey: $this->config->getPrivateKey(),
            );

            $metadataPayload = Arr::get(
                $this->getDumpMetadata($this->diskHelper->getLocalDisk()->path($localFilePath)),
                'meta'
            );

            if (!is_array($metadataPayload)) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved incomplete decrypted dump metadata.');
            }

            $this->diskHelper->copyLocalFileToDisk(
                localFilePath: $localFilePath,
                destinationFilePath: $destinationFilePath,
                disk: $disk,
            );

            $this->diskHelper->writeMetadataFile($disk, $destinationFilePath, $metadataPayload);
        } finally {
            $this->diskHelper->deleteLocalFiles($localFilePath);
            $stream->close();
        }

        return $destinationFilePath;
    }

    public function generateFileDownloadResponse(
        Request $request,
    ): Response|StreamedResponse
    {
        $shouldEncrypt = $this->config->shouldEncrypt();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$shouldEncrypt || $request->user()?->tokenCan('protector:import')) {
            try {
                $serverFile = $this->generateDump();
                $publicKey = '';

                if (!$serverFile) {
                    throw new FailedDumpGenerationException('Dump could not be created.');
                }

                if ($shouldEncrypt) {
                    $publicKey = app(CrypterContract::class)->getPublicKeyFromUser($request->user());

                    if (!$publicKey) {
                        throw new InvalidConfigurationException('The user does not have a public key, which is needed for encrypting the dump.');
                    }
                }
            } catch (InvalidConnectionException|FailedDumpGenerationException|InvalidConfigurationException $exception) {
                Log::error($exception);

                return response($exception->getMessage(), 500, ['message' => $exception->getMessage()]);
            } catch (ShellAccessDeniedException $exception) {
                Log::error($exception);

                return response($exception->httpResponse, 500, ['message' => $exception->httpResponse]);
            } catch (Throwable $throwable) {
                Log::error($throwable);

                return response($throwable->getMessage(), 500, ['message' => 'Unknown error, please check server logs for details.']);
            }

            $chunkSize = $this->config->getChunkSize();

            return response()->streamDownload(
                function () use ($publicKey, $serverFile, $chunkSize, $shouldEncrypt) {
                    $inputHandle = $this->diskHelper->getLocalDisk()->readStream($serverFile);

                    if (!is_resource($inputHandle)) {
                        throw new FailedDumpGenerationException('Could not read generated dump file from local disk.');
                    }

                    while (!feof($inputHandle)) {
                        $chunk = fread($inputHandle, $chunkSize);

                        // Encrypt the data when Laravel Sanctum is active.
                        if ($shouldEncrypt) {
                            $chunk = app(CrypterContract::class)->encrypt($chunk, $publicKey);
                        }

                        echo $chunk;
                    }

                    fclose($inputHandle);
                    $this->diskHelper->deleteLocalFiles($serverFile);
                },
                $this->createFilename(),
                [
                    'Content-Type' => 'text/plain',
                    'Pragma' => 'no-cache',
                    'Expires' => gmdate(DATE_RFC7231, time() - 3600),
                    // Encryption adds some overhead to the chunk, which has to be considered when decrypting it.
                    'Chunk-Size' => $shouldEncrypt ? $chunkSize + app(CrypterContract::class)->determineEncryptionOverhead(
                            $chunkSize,
                            $publicKey
                        ) : $chunkSize,
                    'Sanctum-Enabled' => $shouldEncrypt,
                ]
            );
        }

        throw new UnauthorizedHttpException('', 'Unauthorized');
    }

    public function metadata(): array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getMetadata();
    }

    public function latestDumpName(): string
    {
        return $this->diskHelper->latestDumpName();
    }

    public function dumpFiles(?string $excludeFile = null): Collection
    {
        return $this->diskHelper->dumpFiles($excludeFile);
    }

    public function dumpFile(string $fileName): string
    {
        return $this->diskHelper->dumpFile($fileName);
    }

    public function dumpFilesWithMetadata(): Collection
    {
        return $this->diskHelper->dumpFilesWithMetadata();
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function decryptString(string $encryptedString): string
    {
        $decryptedString = app(CrypterContract::class)->decrypt($encryptedString, $this->config->getPrivateKey());

        if ($decryptedString === false) {
            throw new InvalidConfigurationException(
                'There was an error decrypting the provided string. This might be due to mismatching crypto keys.'
            );
        }

        return $decryptedString;
    }

    /**
     * @throws ShellAccessDeniedException
     */
    public function guardRequiredFunctionsEnabled(): void
    {
        $this->requiredFunctionsCache ??= [
            'proc_open' => $this->checkFunctionExists('proc_open'),
            'proc_close' => $this->checkFunctionExists('proc_close'),
        ];

        if (in_array(false, $this->requiredFunctionsCache, strict: true)) {
            throw new ShellAccessDeniedException($this->requiredFunctionsCache);
        }
    }

    /**
     * Wraps function_exists to allow mocking in tests.
     */
    protected function checkFunctionExists(string $functionName): bool
    {
        return function_exists($functionName);
    }

    public function getDatabaseName(): string
    {
        return $this->config->getDatabaseName();
    }

    protected function generateDump(?array $metadata = null): false|string
    {
        $localDisk = $this->diskHelper->getLocalDisk();
        $localFilePath = $this->diskHelper->localPath();

        $this->getSchemaStateProxy()->dump(
            connection: DB::connection($this->config->getConnectionName()),
            path: $localDisk->path($localFilePath)
        );

        if (!$localDisk->exists($localFilePath) || !$localDisk->size($localFilePath)) {
            $this->diskHelper->deleteLocalFiles($localFilePath);

            return false;
        }

        try {
            // Append some import/export-metadata to the end.
            $metadataToAppend = sprintf(
                "\n-- meta:%s",
                json_encode($metadata ?? $this->metadata(), flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            $localDisk->append($localFilePath, $metadataToAppend);
        } catch (Exception $exception) {
            Log::error($exception);
            $this->diskHelper->deleteLocalFiles($localFilePath);

            return false;
        }

        return $localFilePath;
    }

    /**
     * @throws NoAuthConfiguredException
     * @throws SanctumBasicAuthConflictException
     */
    protected function getConfiguredHttpRequest(): PendingRequest
    {
        $basicAuthCredentials = $this->config->getBasicAuthCredentials();

        if ($this->config->shouldEncrypt()) {
            // Laravel Sanctum and Basic Auth cannot be used simultaneously since they use the same header.
            if ($basicAuthCredentials) {
                throw new SanctumBasicAuthConflictException();
            }

            // Add Bearer token authentication to request.
            $request = Http::withToken($this->config->getAuthToken());
        } elseif ($basicAuthCredentials) {
            // Add basic authentication to request.
            $credentials = explode(':', $basicAuthCredentials);
            $request = Http::withBasicAuth($credentials[0], $credentials[1]);
        } else {
            // Protector cannot be used without any authentication.
            throw new NoAuthConfiguredException();
        }

        return $request->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json'])->timeout($this->config->getHttpTimeout());
    }

    protected function createFilename(): string
    {
        [$appUrl, $database, $connection, $date] = [
            parse_url(config('app.url'), PHP_URL_HOST),
            $this->config->getDatabaseName(),
            $this->config->getConnectionName(),
            now(),
        ];

        return sprintf(
            config('protector.dump.fileName'),
            $appUrl,
            $database,
            $connection,
            $date->year,
            $date->month,
            $date->day,
            $date->hour,
            $date->minute,
            $date->second
        );
    }

    protected function wipeDatabase(Connection $connection): void
    {
        try {
            $connection->getSchemaBuilder()->dropAllViews();
        } catch (LogicException) {
            // ignore logic exceptions.
        }

        try {
            $connection->getSchemaBuilder()->dropAllTables();
        } catch (LogicException) {
            // ignore logic exceptions.
        }

        try {
            $connection->getSchemaBuilder()->dropAllTypes();
        } catch (LogicException) {
            // ignore logic exceptions.
        }
    }

    /**
     * @throws FailedRemoteDatabaseFetchingException
     */
    protected function handleDownloadResponseError(DownloadResponse $response): void
    {
        $httpCode = $response->status();

        throw match ($httpCode) {
            401, 403 => new UnauthorizedHttpException('', $httpCode . ' Unauthorized access'),
            404 => new NotFoundHttpException('404 Not found: ' . $this->config->getDumpEndpointUrl()),
            500 => new FailedRemoteDatabaseFetchingException($response->header('message')),
            default => new HttpException($httpCode, 'Status code ' . $httpCode),
        };
    }

    /**
     * Returns the appended metadata from a local file.
     */
    protected function getDumpMetadata(string $dumpFile): bool|array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getDumpMetadata($dumpFile);
    }

    protected function startTelescopeRecording(bool $wasRecording): void
    {
        if ($wasRecording) {
            \Laravel\Telescope\Telescope::startRecording();
        }
    }

    protected function stopTelescopeRecording(): bool
    {
        if ($isTelescopeRecording = class_exists(
                \Laravel\Telescope\Telescope::class
            ) && \Laravel\Telescope\Telescope::isRecording()) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        return $isTelescopeRecording;
    }

    /**
     * @throws MissingDumpEndpointUrlException
     * @throws MissingPrivateKeyException
     */
    protected function guardDownload(): void
    {
        if ($this->config->shouldEncrypt() && !$this->config->getPrivateKey()) {
            throw new MissingPrivateKeyException();
        }

        if (!$this->config->getDumpEndpointUrl()) {
            throw new MissingDumpEndpointUrlException();
        }
    }

    protected function getConfig(): ProtectorConfigContract
    {
        return $this->config;
    }

    protected function getSchemaStateProxy(): SchemaStateProxyContract
    {
        return app(SchemaStateProxyContract::class, ['protectorConfig' => $this->config]);
    }
}
