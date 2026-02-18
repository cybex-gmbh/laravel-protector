<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\Metadata\MetadataHandler;
use Cybex\Protector\Classes\SchemaState\MySql\MySqlSchemaStateProxy;
use Cybex\Protector\Classes\SchemaState\Postgres\PostgresSchemaStateProxy;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedImportException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FailedWipeException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Exceptions\UnsupportedDatabaseException;
use Cybex\Protector\Traits\HasConfiguration;
use Exception;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\MySqlSchemaState;
use Illuminate\Database\Schema\PostgresSchemaState;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Psr\Http\Message\StreamInterface;
use SodiumException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Protector
{
    use HasConfiguration;

    /**
     * Cache for the runtime-metadata for a new dump.
     */
    protected array $metadataCache = [];

    protected array $requiredFunctionsCache;

    protected array $schemaStateParameters;

    public function __construct(?string $connectionName = null)
    {
        $this->withConnectionName($connectionName)
            ->withDefaultMaxPacketLength()
            ->withoutCreateDb()
            ->withoutTablespaces();
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

        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        if (!file_exists($sourceFilePath)) {
            throw new FileNotFoundException($sourceFilePath);
        }

        if (!Arr::get($options, 'no-wipe')) {
            try {
                $this->wipeDatabase(DB::connection($this->connectionName));
            } catch (Throwable $exception) {
                throw new FailedWipeException($exception->getMessage());
            }
        }

        try {
            $this->getProxyForSchemaState()->load($sourceFilePath);
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
     * Gets the current schema state parameters.
     * These may change between calls, as the protector could be reconfigured to use a different connection and thus a different schema state proxy.
     *
     * @return array
     */
    public function getSchemaStateParameters(): array
    {
        $this->getProxyForSchemaState();

        return $this->schemaStateParameters;
    }

    /**
     * Public function to create the Destination File Path for the dump.
     */
    public function createDestinationFilePath(string $fileName, ?string $subFolder = null): string
    {
        return implode(
            DIRECTORY_SEPARATOR,
            array_filter([
                $this->getConfigValueForKey('baseDirectory'),
                $subFolder,
                $fileName,
            ])
        );
    }

    /**
     * Public function to create a dump for the given configuration.
     *
     * @throws FailedDumpGenerationException
     * @throws InvalidConnectionException
     */
    public function createDump(array $options = []): string
    {
        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        $this->guardRequiredFunctionsEnabled();

        return $this->generateDump($options) ?: throw new FailedDumpGenerationException('Dump could not be created.');
    }

    /**
     * Returns the appended metadata from a file.
     */
    public function getDumpMetadata(string $dumpFile): bool|array
    {
        return app(MetadataHandler::class)->getDumpMetadata($dumpFile);
    }

    /**
     * Deletes all dumps except an optional given file.
     */
    public function flush(?string $excludeFile = null): void
    {
        $files = $this->getDumpFiles($excludeFile);

        $this->getDisk()->delete($files);
    }

    /**
     * Reads the remote dump file and stores it on the client disk.
     *
     * @throws FailedRemoteDatabaseFetchingException
     * @throws InvalidConfigurationException
     * @throws InvalidEnvironmentException
     */
    public function getRemoteDump(): string
    {
        $disk = $this->getDisk();

        if ($this->shouldEncrypt() && !$this->getPrivateKey()) {
            throw new InvalidConfigurationException(
                'For using Laravel Sanctum a crypto keypair is required. There was none found in your .env file.'
            );
        }

        if (!$serverUrl = $this->getServerUrl()) {
            throw new InvalidConfigurationException('Server url is not set or invalid.');
        }

        $this->createDirectory($this->getConfigValueForKey('baseDirectory'), $disk);

        $request = $this->getConfiguredHttpRequest();

        if ($isTelescopeRecording = class_exists(
                \Laravel\Telescope\Telescope::class
            ) && \Laravel\Telescope\Telescope::isRecording()) {
            \Laravel\Telescope\Telescope::stopRecording();
        }

        try {
            $response = $request->withoutRedirecting()->post($serverUrl);
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
                404 => new NotFoundHttpException('404 Not found: ' . $serverUrl),
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

            throw new FailedRemoteDatabaseFetchingException(sprintf('Retrieved empty response from %s', $serverUrl));
        }

        return $destinationFilePath;
    }

    /**
     * Generates an SQL dump from the current app database and returns the path to the file.
     */
    protected function generateDump(array $options = []): ?string
    {
        if ($options['no-data'] ?? false) {
            $this->withoutData();
        }

        $schemaStateProxy = $this->getProxyForSchemaState();
        $tempFile = tempnam('', 'protector');

        $schemaStateProxy->dump(DB::connection($this->connectionName), $tempFile);

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
            $this->connectionConfig['database'],
            $this->getConnectionName(),
            now(),
        ];

        return sprintf(
            config('protector.fileName'),
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
    public function getMetadata(bool $refresh = false): array
    {
        if (!$refresh && $this->metadataCache) {
            return $this->metadataCache;
        }

        return $this->metadataCache = app(MetadataHandler::class)->getMetadata();
    }

    /**
     * Returns a config value for a specific key and checks for Callables.
     */
    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Returns the config value for the baseDirectory key.
     */
    public function getBaseDirectory(): string
    {
        return $this->getConfigValueForKey('baseDirectory') ?? '';
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
        ?string $connectionName = null
    ): Response|StreamedResponse
    {
        $shouldEncrypt = $this->shouldEncrypt();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$shouldEncrypt || $request->user()?->tokenCan('protector:import')) {
            if ($this->withConnectionName($connectionName)) {
                try {
                    $serverFilePath = $this->createDump();
                    $publicKey = $this->getPublicKey($request);
                } catch (InvalidConnectionException|FailedDumpGenerationException|InvalidConfigurationException $exception) {
                    Log::error($exception);

                    return response($exception->getMessage(), 500, ['message' => $exception->getMessage()]);
                } catch (ShellAccessDeniedException $exception) {
                    Log::error($exception);

                    return response($exception->httpResponse, 500, ['message' => $exception->httpResponse]);
                }

                $chunkSize = $this->getConfigValueForKey('chunkSize');

                return response()->streamDownload(
                    function () use ($publicKey, $serverFilePath, $chunkSize, $shouldEncrypt) {
                        $inputHandle = fopen($serverFilePath, 'rb');

                        while (!feof($inputHandle)) {
                            $chunk = fread($inputHandle, $chunkSize);

                            // Encrypt the data when Laravel Sanctum is active.
                            if ($shouldEncrypt) {
                                $chunk = sodium_crypto_box_seal($chunk, $publicKey);
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
        }

        throw new UnauthorizedHttpException('', 'Unauthorized');
    }

    /**
     * Returns the disk which is stated in the config. If no disk is stated the default filesystem disk will be returned.
     */
    public function getDisk(?string $diskName = null): Filesystem
    {
        $diskName ??= $this->getConfigValueForKey('diskName', config('filesystems.default'));

        return Storage::disk($diskName);
    }

    /**
     * Creates a directory at the given path, if it doesn't exist already.
     *
     * @throws FailedCreatingDestinationPathException
     */
    protected function createDirectory(string $destinationPath, FilesystemAdapter $disk): void
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
     * Configure Http request with either the sanctum token or htaccess credentials.
     *
     * @throws InvalidConfigurationException
     */
    protected function getConfiguredHttpRequest(): PendingRequest
    {
        $htaccessLogin = $this->getConfigValueForKey('remoteEndpoint.htaccessLogin');

        if ($this->shouldEncrypt()) {
            // Laravel Sanctum and htaccess cannot be used simultaneously since they use the same header.
            if ($htaccessLogin) {
                throw new InvalidConfigurationException('Laravel Sanctum and Htaccess can not be used simultaneously');
            }

            // Add Bearer token authentication to request.
            $request = Http::withToken($this->getAuthToken());
        } elseif ($htaccessLogin) {
            // Add basic authentication to request.
            $credentials = explode(':', $htaccessLogin);
            $request = Http::withBasicAuth($credentials[0], $credentials[1]);
        } else {
            // Protector cannot be used without any authentication.
            throw new InvalidConfigurationException(
                'Either Laravel Sanctum has to be active or a htaccess login has to be defined.'
            );
        }

        return $request->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json'])->timeout($this->getConfigValueForKey('httpTimeout', 120));
    }

    /**
     * Returns the name of the most recent dump.
     *
     * @throws FileNotFoundException
     */
    public function getLatestDumpName(): string
    {
        $disk = $this->getDisk();
        $baseDirectory = $this->getConfigValueForKey('baseDirectory');
        $files = $disk->files($baseDirectory);

        if (empty($files)) {
            throw new FileNotFoundException($disk->path($baseDirectory));
        }

        usort($files, function ($a, $b) use ($disk) {
            return $disk->lastModified($b) - $disk->lastModified($a);
        });

        return $files[0];
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function decryptString(string $encryptedString): string
    {
        $decryptedString = sodium_crypto_box_seal_open($encryptedString, sodium_hex2bin($this->getPrivateKey()));

        if ($decryptedString === false) {
            throw new InvalidConfigurationException(
                'There was an error decrypting the provided string. This might be due to mismatching crypto keys.'
            );
        }

        return $decryptedString;
    }

    /**
     * @throws InvalidConfigurationException
     */
    protected function getPublicKey(Request $request): string
    {
        try {
            $publicKey = sodium_hex2bin($request->user()?->protector_public_key);
        } catch (SodiumException) {
            throw new InvalidConfigurationException(
                'There was an error receiving the crypto keys. This might be due to mismatching crypto keys.'
            );
        }

        return $publicKey;
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

        $stream = $this->getDisk()->readStream($diskFilePath);

        if (!is_resource($stream)) {
            fclose($handle);

            throw new FileNotFoundException($diskFilePath);
        }

        stream_copy_to_stream($stream, $handle);

        fclose($handle);

        return $tempFilePath;
    }

    public function getDumpFiles(?string $excludeFile = null): array
    {
        $files = $this->getDisk()->files($this->getBaseDirectory());

        if ($excludeFile) {
            $files = array_diff($files, [$excludeFile]);
        }

        return $files;
    }

    /**
     * Returns whether the Sanctum middleware is activated in the config.
     */
    protected function shouldEncrypt(): bool
    {
        return in_array('auth:sanctum', config('protector.routeMiddleware'));
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
            $this->getConfigValueForKey('baseDirectory'),
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

        $outputHandle = fopen($this->getDisk()->path($destinationFilePath), 'wb');

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
        $encryptedChunk = sodium_crypto_box_seal($chunk, $publicKey);

        return strlen($encryptedChunk) - $chunkSize;
    }

    /**
     * @throws UnsupportedDatabaseException
     */
    protected function getProxyForSchemaState(): SchemaState
    {
        $connection = DB::connection($this->connectionName);
        $schemaState = $connection->getSchemaState();

        $schemaStateProxy = match (get_class($schemaState)) {
            MySqlSchemaState::class => app(MySqlSchemaStateProxy::class, [$schemaState, $this]),
            PostgresSchemaState::class => app(PostgresSchemaStateProxy::class, [$schemaState, $this]),
            //            SqliteSchemaState::class => app('SqliteSchemaStateProxy', [$schemaState, $this]),
            default => throw new UnsupportedDatabaseException('Unsupported database schema state: ' . class_basename($schemaState)),
        };

        $this->schemaStateParameters = $schemaStateProxy->getParameters();

        return $schemaStateProxy;
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
}
