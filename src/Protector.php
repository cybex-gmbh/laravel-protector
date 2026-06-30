<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\MetadataHandler;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\DiskHelperContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\EmptyFileWrittenException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedReadingFromDiskException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
use Cybex\Protector\Exceptions\FailedWritingToDiskException;
use Cybex\Protector\Exceptions\FileNameMayNotContainDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingDumpEndpointUrlException;
use Cybex\Protector\Exceptions\InvalidConfiguration\MissingPrivateKeyException;
use Cybex\Protector\Exceptions\InvalidConfiguration\NoAuthConfiguredException;
use Cybex\Protector\Exceptions\InvalidConfiguration\SanctumBasicAuthConflictException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Illuminate\Contracts\Container\BindingResolutionException;
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
use JsonException;
use Laravel\Telescope\Telescope;
use LogicException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Protector
{
    protected array $requiredPhpFunctionsCache;
    protected DiskHelperContract $diskHelper;

    public function __construct(
        protected ProtectorConfigContract $config
    )
    {
        $this->diskHelper = app(DiskHelperContract::class);
    }

    /**
     * Returns a new Protector instance with the given configuration.
     *
     * @throws BindingResolutionException
     */
    public static function withConfig(ProtectorConfigContract $config): static
    {
        return app()->makeWith('protector', ['config' => $config]);
    }

    /**
     * Imports a specific SQL dump.
     *
     * @param string $sourceFilePath A file path on the specified disk.
     * @param Filesystem|null $sourceDisk Defaults to the Protector storage disk.
     * @param bool|null $wipeDb Whether the database should be wiped before import.
     * @param bool|null $migrate Whether to run migrations after import.
     * @param bool|null $allowProduction Allow importing in the production enviroment.
     * @param bool|null $copy Whether to create a temporary copy of the file on the local disk. Only disable this if the given file on the storage disk is available on the local filesystem.
     *
     * @return void
     *
     * @throws FailedImportException
     * @throws FailedReadingFromDiskException
     * @throws FailedWipeException
     * @throws FailedWritingToDiskException
     * @throws FileNotFoundException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     * @throws ShellAccessDeniedException
     */
    public function import(
        string $sourceFilePath,
        ?Filesystem $sourceDisk = null,
        ?bool $wipeDb = true,
        ?bool $migrate = false,
        ?bool $allowProduction = false,
        ?bool $copy = true,
    ): void
    {
        $this->validateSystemRequirements();

        // Production environment is not allowed unless set in options.
        if (App::environment('production') && !$allowProduction) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        $sourceDisk ??= $this->diskHelper->getStorageDisk();

        if ($copy) {
            $localFileName = $this->diskHelper->copyToLocal(sourceFilePath: $sourceFilePath, sourceDisk: $sourceDisk);
            $absoluteImportFilePath = $this->diskHelper->getLocalDisk()->path($localFileName);
        } else {
            $absoluteImportFilePath = $sourceDisk->path($sourceFilePath);
        }

        if (!file_exists($absoluteImportFilePath)) {
            throw new FileNotFoundException($absoluteImportFilePath);
        }

        try {
            if ($wipeDb) {
                try {
                    $this->wipeDatabase(DB::connection($this->config->getConnectionName()));
                } catch (Throwable $throwable) {
                    throw new FailedWipeException($throwable->getMessage(), previous: $throwable);
                }
            }

            try {
                $this->getSchemaStateProxy()->load($absoluteImportFilePath);
            } catch (Throwable $throwable) {
                throw new FailedImportException($throwable->getMessage(), previous: $throwable);
            }
        } finally {
            if ($copy) {
                $this->diskHelper->deleteLocalFile($localFileName);
            }
        }

        if ($migrate) {
            $output = app()->runningInConsole() ? new ConsoleOutput() : null;

            Artisan::call('migrate', parameters: ['--force' => $allowProduction], outputBuffer: $output);
            $output?->writeln('');
        }
    }

    /**
     * Generates a dump from the current app database and saves it to the specified disk (defaults to the storage disk).
     *
     * @param string|null $targetFileName Optional file name on the disk to which the dump is written.
     * @param Filesystem|null $targetDisk Defaults to the Protector storage disk.
     * @param bool|null $copy Whether to create a temporary copy on the local disk. Only use this if the storage disk is available on the local filesystem.
     *
     * @return string The dump file name on the disk.
     *
     * @throws BindingResolutionException
     * @throws EmptyFileWrittenException
     * @throws FailedDumpGenerationException
     * @throws FailedReadingFromDiskException
     * @throws FailedWritingMetadataFileException
     * @throws FailedWritingToDiskException
     * @throws FileNameMayNotContainDirectoryException
     * @throws InvalidConnectionException
     * @throws JsonException
     * @throws ShellAccessDeniedException
     * @throws Throwable
     */
    public function export(?string $targetFileName = null, ?Filesystem $targetDisk = null, ?bool $copy = true): string
    {
        $this->validateSystemRequirements();

        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        if (!$this->diskHelper->isBaseName($targetFileName)) {
            throw new FileNameMayNotContainDirectoryException($targetFileName);
        }

        $targetFileName ??= $this->createFilename();
        $targetDisk ??= $this->diskHelper->getStorageDisk();
        $metadata = $this->metadata();

        $this->diskHelper->writeMetadataFile($targetFileName, $metadata, $targetDisk);

        try {
            if ($copy) {
                $localDumpFile = $this->generateDump($metadata);

                $this->diskHelper->moveFromLocal(
                    localFileName: $localDumpFile,
                    targetFileName: $targetFileName,
                    targetDisk: $targetDisk,
                );
            } else {
                $this->generateDump(metadata: $metadata, fileName: $targetFileName, localDisk: $targetDisk);
            }
        } catch (Throwable $throwable) {
            $this->diskHelper->deleteFileOnDisk($targetFileName, $targetDisk);

            throw $throwable;
        }

        return $targetFileName;
    }

    /**
     * Downloads a dump file from a remote system and stores it on the specified disk (defaults to the storage disk).
     *
     * @param string|null $targetFileName Optional file name on the disk to which the dump is written.
     * @param Filesystem|null $targetDisk Defaults to the storage disk.
     *
     * @return string The dump file name on the disk.
     *
     * @throws EmptyFileWrittenException
     * @throws FailedReadingFromDiskException
     * @throws FailedRemoteDatabaseFetchingException
     * @throws FailedWritingMetadataFileException
     * @throws FailedWritingToDiskException
     * @throws MissingDumpEndpointUrlException
     * @throws MissingPrivateKeyException
     * @throws NoAuthConfiguredException
     * @throws SanctumBasicAuthConflictException
     * @throws Throwable
     */
    public function download(?string $targetFileName = null, ?Filesystem $targetDisk = null): string
    {
        [$targetFileName] = $this->downloadToDisk(
            targetFileName: $targetFileName,
            targetDisk: $targetDisk,
        );

        return $targetFileName;
    }

    /**
     * Downloads a dump file from a remote system and stores it on the specified disk (defaults to the storage disk).
     * Afterwards the dump will be imported, without re-downloading it from storage.
     *
     * @param string|null $targetFileName Optional file name on the disk to which the dump is written.
     * @param Filesystem|null $targetDisk Defaults to the storage disk.
     * @param bool|null $wipeDb Whether the database should not be wiped before import.
     * @param bool|null $migrate Whether to run migrations after import.
     * @param bool|null $allowProduction Allow importing in the production enviroment.
     *
     * @return string The file name of the dump on the storage disk.
     *
     * @throws EmptyFileWrittenException
     * @throws FailedImportException
     * @throws FailedReadingFromDiskException
     * @throws FailedRemoteDatabaseFetchingException
     * @throws FailedWipeException
     * @throws FailedWritingMetadataFileException
     * @throws FailedWritingToDiskException
     * @throws FileNotFoundException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     * @throws MissingDumpEndpointUrlException
     * @throws MissingPrivateKeyException
     * @throws NoAuthConfiguredException
     * @throws SanctumBasicAuthConflictException
     * @throws ShellAccessDeniedException
     * @throws Throwable
     */
    public function downloadAndImport(
        ?string $targetFileName = null,
        ?Filesystem $targetDisk = null,
        ?bool $wipeDb = true,
        ?bool $migrate = false,
        ?bool $allowProduction = false,
    ): string
    {
        [$targetFileName, $localFileName] = $this->downloadToDisk(
            targetFileName: $targetFileName,
            targetDisk: $targetDisk,
            keepLocalFile: true,
        );

        try {
            $this->import(
                sourceFilePath: $localFileName,
                sourceDisk: $this->diskHelper->getLocalDisk(),
                wipeDb: $wipeDb,
                migrate: $migrate,
                allowProduction: $allowProduction,
                copy: false,
            );
        } finally {
            $this->diskHelper->deleteLocalFile($localFileName);
        }

        return $targetFileName;
    }

    /**
     * @throws EmptyFileWrittenException
     * @throws FailedReadingFromDiskException
     * @throws FailedRemoteDatabaseFetchingException
     * @throws FailedWritingMetadataFileException
     * @throws FailedWritingToDiskException
     * @throws MissingDumpEndpointUrlException
     * @throws MissingPrivateKeyException
     * @throws NoAuthConfiguredException
     * @throws SanctumBasicAuthConflictException
     * @throws Throwable
     */
    protected function downloadToDisk(?string $targetFileName = null, ?Filesystem $targetDisk = null, bool $keepLocalFile = false): array
    {
        $this->guardDownload($targetFileName);

        // Telescope is interfering with the request / response.
        $telescopeWasRecording = $this->stopTelescopeRecording();

        $request = $this->getConfiguredHttpRequest();

        try {
            $response = $request->withoutRedirecting()->post($this->config->getDumpEndpointUrl());
        } catch (Throwable $throwable) {
            throw new FailedRemoteDatabaseFetchingException($throwable->getMessage(), previous: $throwable);
        } finally {
            $this->startTelescopeRecording($telescopeWasRecording);
        }

        if (!$response->ok()) {
            $this->handleDownloadResponseError($response);
        }

        $targetFileName ??= $this->diskHelper->getDownloadDestinationFileName($response->header('Content-Disposition'));
        $targetDisk ??= $this->diskHelper->getStorageDisk();

        $stream = $response->toPsrResponse()->getBody();
        $localFileName = $this->diskHelper->createLocalFileName();
        $shouldEncrypt = filter_var($response->header('Sanctum-Enabled'), FILTER_VALIDATE_BOOLEAN);

        try {
            $this->diskHelper->writeStreamToLocalFile(
                stream: $stream,
                localFileName: $localFileName,
                chunkSize: $response->header('Chunk-Size'),
                shouldEncrypt: $shouldEncrypt,
                privateKey: $shouldEncrypt ? $this->config->getPrivateKey() : null,
            );

            $metadataPayload = Arr::get(
                $this->getDumpMetadata($localFileName),
                'meta'
            );

            if (!is_array($metadataPayload)) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved incomplete dump metadata.');
            }

            $this->diskHelper->writeMetadataFile($targetFileName, $metadataPayload, $targetDisk);

            $this->diskHelper->moveFromLocal(
                localFileName: $localFileName,
                targetFileName: $targetFileName,
                targetDisk: $targetDisk,
                keepLocalFile: $keepLocalFile,
            );
        } catch (Throwable $throwable) {
            $this->diskHelper->deleteLocalFile($localFileName);

            throw $throwable;
        } finally {
            $stream->close();
        }

        return [$targetFileName, $localFileName];
    }

    public function generateFileDownloadResponse(Request $request): Response|StreamedResponse
    {
        $shouldEncrypt = $this->config->shouldEncrypt();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$shouldEncrypt || $request->user()?->tokenCan('protector:import')) {
            try {
                $serverFile = $this->generateDump();
                $publicKey = '';

                if ($shouldEncrypt) {
                    $publicKey = app(CrypterContract::class)->getPublicKeyFromUser($request->user());

                    if (!$publicKey) {
                        throw new InvalidConfigurationException('The user does not have a public key, which is needed for encrypting the dump.');
                    }
                }
            } catch (InvalidConnectionException|FailedDumpGenerationException|InvalidConfigurationException $exception) {
                Log::error($exception);
                $this->diskHelper->deleteLocalFile($serverFile);

                return response($exception->getMessage(), 500, ['message' => $exception->getMessage()]);
            } catch (ShellAccessDeniedException $exception) {
                Log::error($exception);
                $this->diskHelper->deleteLocalFile($serverFile);

                return response($exception->httpResponse, 500, ['message' => $exception->httpResponse]);
            } catch (Throwable $throwable) {
                Log::error($throwable);
                $this->diskHelper->deleteLocalFile($serverFile);

                return response($throwable->getMessage(), 500, ['message' => 'Unknown error, please check server logs for details.']);
            }

            $chunkSize = $this->config->getChunkSize();

            return response()->streamDownload(
                function () use ($publicKey, $serverFile, $chunkSize, $shouldEncrypt) {
                    try {
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
                    } finally {
                        is_resource($inputHandle) && fclose($inputHandle);
                        $this->diskHelper->deleteLocalFile($serverFile);
                    }
                },
                $this->createFilename(),
                [
                    'Content-Type' => $shouldEncrypt ? 'application/octet-stream' : 'text/plain',
                    'Pragma' => 'no-cache',
                    'Cache-Control' => 'no-cache',
                    'Expires' => 0,
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

    /**
     * @throws BindingResolutionException
     */
    public function metadata(): array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getMetadata();
    }

    /**
     * @throws EmptyDumpDirectoryException
     */
    public function latestDumpName(): string
    {
        return $this->diskHelper->latestDumpName();
    }

    /**
     * Returns all dump files on the storage disk root.
     */
    public function dumpFiles(?string $excludeFile = null): Collection
    {
        return $this->diskHelper->dumpFiles($excludeFile);
    }

    /**
     * Returns all dump files on the storage disk root including their metadata.
     */
    public function dumpFilesWithMetadata(): Collection
    {
        return $this->diskHelper->dumpFilesWithMetadata();
    }

    /**
     * @throws ShellAccessDeniedException
     */
    public function validateSystemRequirements(): void
    {
        $this->requiredPhpFunctionsCache ??= [
            'proc_open' => $this->checkFunctionExists('proc_open'),
            'proc_close' => $this->checkFunctionExists('proc_close'),
        ];

        if (in_array(false, $this->requiredPhpFunctionsCache, strict: true)) {
            throw new ShellAccessDeniedException($this->requiredPhpFunctionsCache);
        }
    }

    public function getDatabaseName(): string
    {
        return $this->config->getDatabaseName();
    }

    /**
     * Wraps function_exists to allow mocking in tests.
     */
    protected function checkFunctionExists(string $functionName): bool
    {
        return function_exists($functionName);
    }

    /**
     * @throws BindingResolutionException
     * @throws EmptyFileWrittenException
     * @throws FailedDumpGenerationException
     * @throws JsonException
     * @throws Throwable
     */
    protected function generateDump(?array $metadata = null, ?string $fileName = null, ?Filesystem $localDisk = null): string
    {
        $localDisk ??= $this->diskHelper->getLocalDisk();
        $fileName ??= $this->diskHelper->createLocalFileName();

        try {
            $this->dump($fileName, $localDisk);

            // Append some import/export-metadata to the end.
            $metadataToAppend = sprintf(
                "\n-- meta:%s",
                json_encode($metadata ?? $this->metadata(), flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            $localDisk->append($fileName, $metadataToAppend);
        } catch (Throwable $throwable) {
            $localDisk->delete($fileName);

            throw $throwable;
        }

        return $fileName;
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
     * @throws HttpException
     * @throws NotFoundHttpException
     * @throws UnauthorizedHttpException
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
     *
     * @throws BindingResolutionException
     * @throws FileNotFoundException
     */
    protected function getDumpMetadata(string $dumpFileName): bool|array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getDumpMetadata($dumpFileName);
    }

    protected function startTelescopeRecording(bool $wasRecording): void
    {
        if ($wasRecording) {
            Telescope::startRecording();
        }
    }

    protected function stopTelescopeRecording(): bool
    {
        if ($isTelescopeRecording = class_exists(
                Telescope::class
            ) && Telescope::isRecording()) {
            Telescope::stopRecording();
        }

        return $isTelescopeRecording;
    }

    /**
     * @throws FileNameMayNotContainDirectoryException
     * @throws MissingDumpEndpointUrlException
     * @throws MissingPrivateKeyException
     */
    protected function guardDownload(?string $storageFileName = null): void
    {
        if ($this->config->shouldEncrypt() && !$this->config->getPrivateKey()) {
            throw new MissingPrivateKeyException();
        }

        if (!$this->config->getDumpEndpointUrl()) {
            throw new MissingDumpEndpointUrlException();
        }

        if (!$this->diskHelper->isBaseName($storageFileName)) {
            throw new FileNameMayNotContainDirectoryException($storageFileName);
        }
    }

    /**
     * @throws FailedDumpGenerationException
     * @throws EmptyFileWrittenException
     */
    protected function dump(string $localFileName, Filesystem $localDisk): void
    {
        try {
            $this->getSchemaStateProxy()->dump(
                connection: DB::connection($this->config->getConnectionName()),
                path: $localDisk->path($localFileName)
            );
        } catch (Throwable $throwable) {
            throw new FailedDumpGenerationException($throwable->getMessage(), previous: $throwable);
        }

        if ($localDisk->exists($localFileName) && !$localDisk->size($localFileName)) {
            throw new EmptyFileWrittenException($localFileName, 'local');
        }
    }

    /**
     * For usage in tests.
     */
    protected function getConfig(): ProtectorConfigContract
    {
        return $this->config;
    }

    protected function getSchemaStateProxy(): SchemaStateProxyContract
    {
        return app(SchemaStateProxyContract::class, ['protectorConfig' => $this->config]);
    }
}
