<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\MetadataHandler;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Contracts\SchemaStateProxyContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\FailedWritingMetadataFileException;
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
use Illuminate\Support\Str;
use LogicException;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Protector
{
    protected const string METADATA_FILE_SUFFIX = '.meta';

    protected array $requiredFunctionsCache;

    public function __construct(protected ProtectorConfigContract $config)
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
     * @throws InvalidEnvironmentException
     * @throws InvalidConnectionException
     * @throws FileNotFoundException
     * @throws InvalidConfigurationException
     * @throws FailedImportException
     * @throws FailedWipeException
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

        if (!$this->isAbsolutePath($filePath)) {
            $disk ??= $this->config->getStorageDisk();

            $localFilePath = $this->copyStorageToLocal(
                $filePath,
                $disk,
            );

            $absoluteImportFilePath = $this->config->getLocalDisk()->path($localFilePath);
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
            if (!$this->isAbsolutePath($filePath)) {
                $this->config->getLocalDisk()->delete($localFilePath);
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
     * Public function to create a dump for the given configuration.
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

        $metadata = $this->getMetadata();
        $localDumpFile = $this->generateDump($metadata) ?: throw new FailedDumpGenerationException('Dump could not be created.');

        $disk ??= $this->config->getStorageDisk();
        $filePath ??= implode(DIRECTORY_SEPARATOR, [$this->config->getStorageBaseDirectory(), $this->createFilename()]);

        $localDisk = $this->config->getLocalDisk();
        $stream = $localDisk->readStream($localDumpFile);

        try {
            if (!is_resource($stream)) {
                throw new FailedDumpGenerationException('Could not read generated dump file from local disk.');
            }

            if (!$disk->writeStream($filePath, $stream)) {
                $disk->delete($filePath);

                throw new FailedDumpGenerationException('Could not write generated dump file to the storage disk.');
            }

            $this->writeMetadataFile(
                $disk,
                $filePath,
                $metadata,
            );
        } finally {
            fclose($stream);
            $localDisk->delete($localDumpFile);
        }

        return $filePath;
    }

    /**
     * Returns the appended metadata from a file.
     */
    public function getDumpMetadata(string $dumpFile): bool|array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getDumpMetadata($dumpFile);
    }

    /**
     * Deletes all dumps except an optional given file.
     */
    public function flush(?string $excludeFile = null): void
    {
        $files = $this->getDumpFiles($excludeFile)
            ->flatMap(fn(string $dumpFilePath) => [$dumpFilePath, $this->createMetadataFilePath($dumpFilePath)]);

        $this->config->getStorageDisk()->delete($files->toArray());
    }

    /**
     * Reads the remote dump file and stores it on the specified disk or the storage disk.
     *
     * @throws FailedRemoteDatabaseFetchingException
     * @throws MissingPrivateKeyException
     * @throws MissingDumpEndpointUrlException
     * @throws InvalidEnvironmentException
     */
    public function download(?Filesystem $disk = null, ?string $filePath = null): string
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

        return $this->transferDownloadToStorage($response, $disk, $filePath);
    }

    /**
     * Generates an SQL dump from the current app database on the local disk and returns the relative path to the file.
     */
    protected function generateDump(?array $metadata = null): false|string
    {
        $localDisk = $this->config->getLocalDisk();
        $localFilePath = $this->createLocalFilePath();

        $this->getSchemaStateProxy()->dump(
            connection: DB::connection($this->config->getConnectionName()),
            path: $this->config->getLocalDisk()->path($localFilePath)
        );

        if (!$localDisk->exists($localFilePath) || !$localDisk->size($localFilePath)) {
            $this->config->getLocalDisk()->delete($localFilePath);

            return false;
        }

        try {
            // Append some import/export-metadata to the end.
            $metadataToAppend = sprintf(
                "\n-- meta:%s",
                json_encode($metadata ?? $this->getMetadata(), JSON_UNESCAPED_UNICODE)
            );

            $localDisk->append($localFilePath, $metadataToAppend);
        } catch (Exception $exception) {
            Log::error($exception);
            $this->config->getLocalDisk()->delete($localFilePath);

            return false;
        }

        return $localFilePath;
    }

    /**
     * Creates a filename for the dump file.
     */
    public function createFilename(): string
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

    /**
     * Returns the metadata for a new dump.
     */
    public function getMetadata(): array
    {
        return app()->makeWith(MetadataHandler::class, ['protectorConfig' => $this->config])->getMetadata();
    }

    /**
     * Prepares the file download response.
     * Prevents the exposure of the connectionName parameter to routing.
     */
    public function prepareFileDownloadResponse(Request $request): Response|StreamedResponse
    {
        return $this->generateFileDownloadResponse($request);
    }

    /**
     * Generates a response which allows downloading the dump file.
     */
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
                    $inputHandle = $this->config->getLocalDisk()->readStream($serverFile);

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
                    $this->config->getLocalDisk()->delete($serverFile);
                },
                $this->createFilename(),
                [
                    'Content-Type' => 'text/plain',
                    'Pragma' => 'no-cache',
                    'Expires' => gmdate(DATE_RFC7231, time() - 3600),
                    // Encryption adds some overhead to the chunk, which has to be considered when decrypting it.
                    'Chunk-Size' => $shouldEncrypt ? $chunkSize + $this->determineEncryptionOverhead(
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
     * Creates a directory at the given path, if it doesn't exist already.
     *
     * @throws FailedCreatingDestinationPathException
     */
    protected function createDirectory(string $destinationPath, Filesystem $disk): void
    {
        if ($disk->missing($destinationPath)) {
            if ($disk->makeDirectory($destinationPath) === false) {
                throw new FailedCreatingDestinationPathException(
                    sprintf('Could not create the non-existing destination path %s on given disk.', $destinationPath)
                );
            }
        }
    }

    /**
     * Configure Http request with either the Sanctum token or basic auth credentials.
     *
     * @throws SanctumBasicAuthConflictException
     * @throws NoAuthConfiguredException
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

    /**
     * Returns the name of the most recent dump.
     *
     * @throws EmptyBaseDirectoryException
     */
    public function getLatestDumpName(): string
    {
        $files = $this->getDumpFiles();

        if ($files->isEmpty()) {
            throw new EmptyBaseDirectoryException();
        }

        $disk = $this->config->getStorageDisk();

        return $files->sortByDesc(fn($file) => $disk->lastModified($file))->values()[0];
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
     * Copies the specified dump to a local temporary file, in case the dump is stored remotely.
     *
     * @throws FileNotFoundException
     * @throws Throwable
     */
    protected function copyStorageToLocal(string $storageFilePath, ?Filesystem $storageDisk = null): string
    {
        $storageDisk ??= $this->config->getStorageDisk();
        $localDisk = $this->config->getLocalDisk();
        $localFilePath = $this->createLocalFilePath();

        $stream = $storageDisk->readStream($storageFilePath);

        if (!is_resource($stream)) {
            throw new FileNotFoundException($storageFilePath);
        }

        if (!$localDisk->writeStream($localFilePath, $stream)) {
            $localDisk->delete($localFilePath);
        }

        fclose($stream);

        return $localFilePath;
    }

    public function getDumpFiles(?string $excludeFile = null): Collection
    {
        $files = array_values(array_filter(
            $this->config->getStorageDisk()->allFiles($this->config->getStorageBaseDirectory()),
            fn(string $filePath) => !$this->isMetadataFile($filePath)
        ));

        if ($excludeFile) {
            $files = array_diff($files, [$excludeFile]);
        }

        return collect($files);
    }

    /**
     * @throws FileNotFoundException
     */
    public function getDumpFile(string $fileName): string
    {
        $filePathOnDisk = implode(DIRECTORY_SEPARATOR, [$this->config->getStorageBaseDirectory(), $fileName]);

        $file = $this->getDumpFiles()->firstWhere(
            fn($file) => $filePathOnDisk === $file
        );

        if (!$file) {
            throw new FileNotFoundException($filePathOnDisk);
        }

        return $file;
    }

    public function getDumpFilesWithMetadata(): Collection
    {
        return $this->getDumpFiles()->mapWithKeys(
            fn(string $dumpFilePath) => [$dumpFilePath => $this->getMetadataFromMetaFile($dumpFilePath) ?? []]
        );
    }

    /**
     * Throws an exception if required shell functions are deactivated.
     *
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

    public function getStorageDiskName(): string
    {
        return $this->config->getStorageDiskName();
    }

    public function getLocalDiskName(): string
    {
        return $this->config->getLocalDiskName();
    }

    public function getStorageDiskBaseDirectory(): string
    {
        return $this->config->getStorageBaseDirectory();
    }

    public function getLocalDiskBaseDirectory(): string
    {
        return $this->config->getLocalBaseDirectory();
    }

    public function getLocalDisk(): Filesystem
    {
        return $this->config->getLocalDisk();
    }

    public function getStorageDisk(): Filesystem
    {
        return $this->config->getStorageDisk();
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
     * Returns the destination file path for the database dump.
     */
    protected function getDumpDestinationFilePath(string $fileName): string
    {
        if (preg_match('/filename="(?P<filename>.+)"/i', $fileName, $matches)) {
            $destinationFileName = $matches['filename'];
        }

        return sprintf(
            '%s%s%s',
            $this->config->getStorageBaseDirectory(),
            DIRECTORY_SEPARATOR,
            ($destinationFileName ?? 'remote_dump.sql')
        );
    }

    /**
     * Writes the remote database dump to a specified file path.
     * Contents are retrieved in chunks from the provided stream.
     * When the database dump is encrypted (indicated by whether Laravel Sanctum is enabled or not) those chunks will also be decrypted.
     */
    protected function writeDumpToLocal(
        StreamInterface $stream,
        string $destinationFilePath,
        int $chunkSize,
        bool $sanctumEnabled
    ): void
    {
        $outputHandle = fopen($this->config->getLocalDisk()->path($destinationFilePath), 'wb');

        // Stop when EOF is reached or an empty chunk was read.
        while (!$stream->eof() && ($chunk = $stream->read($chunkSize)) !== '') {
            if ($sanctumEnabled) {
                $chunk = $this->decryptString($chunk);
            }

            fwrite($outputHandle, $chunk);
        }

        fclose($outputHandle);
    }

    public function createLocalFilePath(): string
    {
        $localDisk = $this->config->getLocalDisk();
        $baseDirectory = $this->config->getLocalBaseDirectory();

        $this->createDirectory($baseDirectory, $localDisk);

        return implode(DIRECTORY_SEPARATOR, [$baseDirectory, uniqid('protector_', true) . '.' . 'sql']);
    }

    protected function isAbsolutePath(string $filePath): bool
    {
        return Str::startsWith($filePath, DIRECTORY_SEPARATOR);
    }

    protected function determineEncryptionOverhead(int $chunkSize, string $publicKey): int
    {
        $chunk = str_repeat('0', $chunkSize);
        $encryptedChunk = app(CrypterContract::class)->encrypt($chunk, $publicKey);

        return strlen($encryptedChunk) - $chunkSize;
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

    // Usage for tests only.

    /**
     * @param DownloadResponse $response
     * @param Filesystem|null $disk
     * @param string|null $filePath
     * @return string
     * @throws FailedRemoteDatabaseFetchingException
     */
    protected function transferDownloadToStorage(DownloadResponse $response, ?Filesystem $disk = null, ?string $filePath = null): string
    {
        $stream = $response->toPsrResponse()->getBody();

        $disk ??= $this->config->getStorageDisk();
        $localDisk = $this->config->getLocalDisk();

        $destinationFilePath = $filePath ?? $this->getDumpDestinationFilePath($response->header('Content-Disposition'));
        $localFilePath = $this->createLocalFilePath();
        $localFileStream = null;

        try {
            // Needs to be stored local first to validate and extract metadata.
            $this->writeDumpToLocal(
                $stream,
                $localFilePath,
                $response->header('Chunk-Size'),
                $response->header('Sanctum-Enabled'),
            );

            if ($localDisk->size($localFilePath) === 0) {
                $localDisk->delete($localFilePath);
                throw new FailedRemoteDatabaseFetchingException(sprintf('Retrieved empty response from %s', $this->config->getDumpEndpointUrl()));
            }

            // Validate metadata
            $dumpMetadata = $this->getDumpMetadata($localDisk->path($localFilePath));
            $metadataPayload = Arr::get($dumpMetadata, 'meta');

            if (!is_array($metadataPayload)) {
                throw new FailedRemoteDatabaseFetchingException('Retrieved incomplete decrypted dump metadata.');
            }

            $localFileStream = $localDisk->readStream($localFilePath);

            if (!is_resource($localFileStream)) {
                throw new FailedRemoteDatabaseFetchingException('Could not read staged dump file from local disk.');
            }

            if (!$disk->writeStream($destinationFilePath, $localFileStream)) {
                $disk->delete($destinationFilePath);

                throw new FailedRemoteDatabaseFetchingException('Could not write staged dump file to destination storage disk.');
            }

            $this->writeMetadataFile($disk, $destinationFilePath, $metadataPayload);

            if ($disk->size($destinationFilePath) === 0) {
                $disk->delete([$destinationFilePath, $this->createMetadataFilePath($destinationFilePath)]);

                throw new FailedRemoteDatabaseFetchingException('Writing to the storage disk failed.');
            }
        } finally {
            if (is_resource($localFileStream)) {
                fclose($localFileStream);
            }

            $localDisk->delete($localFilePath);

            $stream->close();
        }

        return $destinationFilePath;
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

    protected function stopTelescopeRecording(): bool
    {
        if ($isTelescopeRecording = class_exists(
                \Laravel\Telescope\Telescope::class
            ) && \Laravel\Telescope\Telescope::isRecording()) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        return $isTelescopeRecording;
    }

    protected function startTelescopeRecording(bool $wasRecording): void
    {
        if ($wasRecording) {
            \Laravel\Telescope\Telescope::startRecording();
        }
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

    protected function createMetadataFilePath(string $dumpFilePath): string
    {
        return $dumpFilePath . static::METADATA_FILE_SUFFIX;
    }

    protected function isMetadataFile(string $filePath): bool
    {
        return Str::endsWith($filePath, static::METADATA_FILE_SUFFIX);
    }

    protected function getMetadataFromMetaFile(string $dumpFilePath): ?array
    {
        $metadataFilePath = $this->createMetadataFilePath($dumpFilePath);
        $disk = $this->config->getStorageDisk();

        if (!$disk->exists($metadataFilePath)) {
            return null;
        }

        if (!$metadata = $disk->get($metadataFilePath)) {
            return null;
        }

        return json_decode($metadata, true);
    }

    /**
     * @throws FailedWritingMetadataFileException
     */
    protected function writeMetadataFile(Filesystem $disk, string $dumpFilePath, array $metadataPayload): void
    {
        $metadataFilePath = $this->createMetadataFilePath($dumpFilePath);
        $encodedMetadata = json_encode(['meta' => $metadataPayload], JSON_UNESCAPED_UNICODE);

        if ($disk->put($metadataFilePath, $encodedMetadata) === false) {
            $disk->delete([$dumpFilePath, $metadataFilePath]);

            throw new FailedWritingMetadataFileException($dumpFilePath);
        }
    }
}
