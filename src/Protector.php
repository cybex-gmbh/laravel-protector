<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\MetadataHandler;
use Cybex\Protector\Contracts\CrypterContract;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
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
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
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
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Protector
{
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
     *
     * @throws InvalidEnvironmentException
     * @throws InvalidConnectionException
     * @throws FileNotFoundException
     * @throws InvalidConfigurationException
     * @throws FailedImportException
     * @throws FailedWipeException
     */
    public function importDump(string $sourceFilePath, array $options = []): void
    {
        $this->guardRequiredFunctionsEnabled();

        // Production environment is not allowed unless set in options.
        if (App::environment('production') && !Arr::get($options, 'allow-production')) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        if (!file_exists($sourceFilePath)) {
            throw new FileNotFoundException($sourceFilePath);
        }

        if (!Arr::get($options, 'no-wipe')) {
            try {
                $this->wipeDatabase(DB::connection($this->config->getConnectionName()));
            } catch (Throwable $exception) {
                throw new FailedWipeException($exception->getMessage());
            }
        }

        try {
            $this->config->getProxyForSchemaState()->load($sourceFilePath);
        } catch (Throwable $exception) {
            throw new FailedImportException($exception->getMessage());
        }

        if (Arr::get($options, 'migrate')) {
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
    public function createDump(bool $tempFileOnly = true, ?string $fileName = null): string
    {
        if (!$this->config->getConnectionConfig()) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        $this->guardRequiredFunctionsEnabled();

        $tempFile = $this->generateDump() ?: throw new FailedDumpGenerationException('Dump could not be created.');

        if ($tempFileOnly) {
            return $tempFile;
        }

        $fileName = implode(DIRECTORY_SEPARATOR, [$this->config->getBaseDirectory(), $fileName ?? $this->createFilename()]);

        $this->config->getDisk()->writeStream(
            $fileName,
            fopen($tempFile, 'r')
        );

        return $fileName;
    }

    /**
     * Returns the appended metadata from a file.
     * The file path starts with the configured protector base directory.
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
        $files = $this->getDumpFiles($excludeFile);

        $this->config->getDisk()->delete($files->toArray());
    }

    /**
     * Reads the remote dump file and stores it on the client disk.
     *
     * @throws FailedRemoteDatabaseFetchingException
     * @throws MissingPrivateKeyException
     * @throws MissingDumpEndpointUrlException
     * @throws InvalidEnvironmentException
     */
    public function getRemoteDump(): string
    {
        $disk = $this->config->getDisk();

        if ($this->config->shouldEncrypt() && !$this->config->getPrivateKey()) {
            throw new MissingPrivateKeyException();
        }

        if (!$dumpEndpointUrl = $this->config->getDumpEndpointUrl()) {
            throw new MissingDumpEndpointUrlException();
        }

        $this->createDirectory($this->config->getBaseDirectory(), $disk);

        $request = $this->getConfiguredHttpRequest();

        if ($isTelescopeRecording = class_exists(
                \Laravel\Telescope\Telescope::class
            ) && \Laravel\Telescope\Telescope::isRecording()) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        try {
            $response = $request->withoutRedirecting()->post($dumpEndpointUrl);
        } catch (Exception $exception) {
            throw new FailedRemoteDatabaseFetchingException(
                sprintf('Could not fetch database from remote server: %s', $exception->getMessage())
            );
        } finally {
            if ($isTelescopeRecording) {
                \Laravel\Telescope\Telescope::startRecording();
            }
        }

        if (!$response->ok()) {
            $httpCode = $response->status();

            throw match ($httpCode) {
                401, 403 => new UnauthorizedHttpException('', $httpCode . ' Unauthorized access'),
                404 => new NotFoundHttpException('404 Not found: ' . $dumpEndpointUrl),
                500 => new FailedRemoteDatabaseFetchingException($response->header('message')),
                default => new HttpException($httpCode, 'Status code ' . $httpCode),
            };
        }

        $destinationFilePath = $this->getDumpDestinationFilePath($response->header('Content-Disposition'));
        $stream = $response->toPsrResponse()->getBody();

        $this->writeDumpFile(
            $stream,
            $destinationFilePath,
            $response->header('Chunk-Size'),
            $response->header('Sanctum-Enabled')
        );
        $stream->close();

        if ($disk->size($destinationFilePath) === 0) {
            $disk->delete($destinationFilePath);

            throw new FailedRemoteDatabaseFetchingException(sprintf('Retrieved empty response from %s', $dumpEndpointUrl));
        }

        return $destinationFilePath;
    }

    /**
     * Generates an SQL dump from the current app database and returns the path to the file.
     */
    protected function generateDump(): ?string
    {
        $schemaStateProxy = $this->config->getProxyForSchemaState();
        $tempFile = tempnam('', 'protector');

        $schemaStateProxy->dump(DB::connection($this->config->getConnectionName()), $tempFile);

        if (!filesize($tempFile)) {
            unlink($tempFile);

            $tempFile = null;
        }

        try {
            // Append some import/export-metadata to the end.
            $metadata = sprintf(
                "\n-- meta:%s",
                json_encode($this->getMetadata(), JSON_UNESCAPED_UNICODE)
            );

            file_put_contents($tempFile, $metadata, FILE_APPEND);
        } catch (Exception $exception) {
            Log::error($exception);

            unlink($tempFile);
            $tempFile = null;
        }

        return $tempFile;
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
                $serverFilePath = $this->createDump();
                $publicKey = '';

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
                function () use ($publicKey, $serverFilePath, $chunkSize, $shouldEncrypt) {
                    $inputHandle = fopen($serverFilePath, 'rb');

                    while (!feof($inputHandle)) {
                        $chunk = fread($inputHandle, $chunkSize);

                        // Encrypt the data when Laravel Sanctum is active.
                        if ($shouldEncrypt) {
                            $chunk = app(CrypterContract::class)->encrypt($chunk, $publicKey);
                        }

                        echo $chunk;
                    }

                    fclose($inputHandle);
                    unlink($serverFilePath);
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

        $disk = $this->config->getDisk();

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
     * @throws Exception
     * @throws FileNotFoundException
     */
    public function createTempFilePath(string $diskFilePath): string
    {
        $tempFilePath = tempnam('', 'protector') . '.sql';

        if ($tempFilePath === false) {
            throw new Exception('Could not create a temporary file for dump.');
        }

        $handle = fopen($tempFilePath, 'w');

        if ($handle === false) {
            throw new Exception('Could not open temporary file for writing.');
        }

        $stream = $this->config->getDisk()->readStream($diskFilePath);

        if (!is_resource($stream)) {
            fclose($handle);

            throw new FileNotFoundException($diskFilePath);
        }

        stream_copy_to_stream($stream, $handle);

        fclose($handle);

        return $tempFilePath;
    }

    public function getDumpFiles(?string $excludeFile = null): Collection
    {
        $files = $this->config->getDisk()->files($this->config->getBaseDirectory());

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
        $filePathOnDisk = implode(DIRECTORY_SEPARATOR, [$this->config->getBaseDirectory(), $fileName]);

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
        return $this->getDumpFiles()->mapWithKeys(fn($file) => [
            $file => $this->getDumpMetadata($file),
        ]);
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

    public function getDiskName(): string
    {
        return $this->config->getDiskName();
    }

    public function getDiskBaseDirectory(): string
    {
        return $this->config->getBaseDirectory();
    }

    public function getDatabaseName(): string
    {
        return $this->config->getDatabaseName();
    }

    public function getPrivateKeyEnvKeyName(): string
    {
        return $this->config->getPrivateKeyName();
    }

    public function getAuthTokenEnvKeyName(): string
    {
        return $this->config->getAuthTokenKeyName();
    }

    public function getDumpEndpointUrlEnvKeyName(): string
    {
        return $this->config->getDumpEndpointUrlKeyName();
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
            $this->config->getBaseDirectory(),
            DIRECTORY_SEPARATOR,
            ($destinationFileName ?? 'remote_dump.sql')
        );
    }

    /**
     * Writes the remote database dump to a specified file path.
     * Contents are retrieved in chunks from the provided stream.
     * When the database dump is encrypted (indicated by whether Laravel Sanctum is enabled or not) those chunks will also be decrypted.
     */
    protected function writeDumpFile(
        StreamInterface $stream,
        string $destinationFilePath,
        int $chunkSize,
        bool $sanctumEnabled
    ): void
    {
        $resource = StreamWrapper::getResource($stream);

        $outputHandle = fopen($this->config->getDisk()->path($destinationFilePath), 'wb');

        // Stop when EOF is reached or an empty chunk was read.
        while (!feof($resource) && $chunk = stream_get_contents($resource, $chunkSize)) {
            if ($sanctumEnabled) {
                $chunk = $this->decryptString($chunk);
            }

            fwrite($outputHandle, $chunk);
        }

        fclose($outputHandle);
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
            $connection->getSchemaBuilder()->dropAllTables();
            $connection->getSchemaBuilder()->dropAllTypes();
        } catch (LogicException) {
            // ignore logic exceptions.
        }
    }

    // Usage for tests only.
    protected function getConfig(): ProtectorConfigContract
    {
        return $this->config;
    }
}
