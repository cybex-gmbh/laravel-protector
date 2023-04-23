<?php

namespace Cybex\Protector;

use Cybex\Protector\Classes\MySqlSchemaStateProxy;
use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedMysqlCommandException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
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
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use SodiumException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Protector
{
    use HasConfiguration;

    /**
     * Cache for the runtime-metadata for a new dump.
     *
     * @var array
     */
    protected array $metaDataCache = [];

    public function __construct(string $connectionName = null)
    {
        $this->withConnectionName($connectionName)
             ->withDefaultMaxPacketLength()
             ->withoutCreateDb()
             ->withoutTablespaces();
    }

    /**
     * Imports a specific SQL dump.
     *
     * @throws FailedMysqlCommandException
     * @throws InvalidEnvironmentException
     * @throws InvalidConnectionException
     * @throws FileNotFoundException
     * @throws InvalidConfigurationException
     */
    public function importDump(string $sourceFilePath, array $options = []): void
    {
        $this->guardExecEnabled();

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

        $shellCommandDropCreateDatabase = sprintf(
            'mysql -h%s -u%s -p%s -e %s 2> /dev/null',
            escapeshellarg($this->connectionConfig['host']),
            escapeshellarg($this->connectionConfig['username']),
            escapeshellarg($this->connectionConfig['password']),
            escapeshellarg(sprintf('drop database %1$s; create database %1$s;', $this->connectionConfig['database']))
        );

        $shellCommandImport = sprintf(
            'mysql -h%s -u%s -p%s -D%s < %s 2> /dev/null',
            escapeshellarg($this->connectionConfig['host']),
            escapeshellarg($this->connectionConfig['username']),
            escapeshellarg($this->connectionConfig['password']),
            escapeshellarg($this->connectionConfig['database']),
            escapeshellarg($sourceFilePath)
        );

        exec($shellCommandDropCreateDatabase, result_code: $resultCode);

        if ($resultCode != 0) {
            throw new FailedMysqlCommandException();
        } else {
            exec($shellCommandImport, result_code: $resultCode);

            if ($resultCode != 0) {
                throw new FailedMysqlCommandException();
            }
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

        $this->guardExecEnabled();

        return $this->generateDump($options) ?: throw new FailedDumpGenerationException('Dump could not be created.');
    }

    /**
     * Returns the appended Meta-Data from a file.
     */
    public function getDumpMetaData(string $dumpFile): bool|array
    {
        $desiredMetaLines = [
            'options',
            'meta',
        ];

        $lines = $this->tail($dumpFile, count($desiredMetaLines));

        // Response has not enough lines.
        if (count($lines) < count($desiredMetaLines)) {
            return false;
        }

        $data = [];

        foreach ($lines as $line) {
            $matches = [];

            // Check if the structure is correct.
            if (preg_match('/^-- (?<type>[a-z0-9]+):(?<data>.+)$/i', $line, $matches)) {
                // Check if the given type is a desired result for meta-data.
                if (in_array($matches['type'], $desiredMetaLines)) {
                    $decodedData = json_decode($matches['data'], true);

                    // We store json-encoded arrays, if we do not get an array back, that means something went wrong.
                    if (!is_array($decodedData)) {
                        return false;
                    }

                    $data[$matches['type']] = $decodedData;
                }
            }
        }

        return $data;
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

        if (App::environment('production')) {
            throw new InvalidEnvironmentException('Retrieving a dump is not allowed on production systems.');
        }

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
                401, 403 => new UnauthorizedHttpException('', $httpCode.' Unauthorized access'),
                404 => new NotFoundHttpException('404 Not found: '.$serverUrl),
                500 => new FailedRemoteDatabaseFetchingException($response->header('message')),
                default => new HttpException($httpCode, 'Status code '.$httpCode),
            };
        }

        $destinationFilePath = $this->getDumpDestinationFilePath($response->header('Content-Disposition'));
        $stream              = $response->toPsrResponse()->getBody();

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
     * Returns whether the app is under git version control based on a filesystem check.
     */
    public function isUnderGitVersionControl(): bool
    {
        return File::exists(base_path('.git'));
    }

    /**
     * Returns the current git-revision.
     */
    protected function getGitRevision(): string
    {
        return @exec('git rev-parse HEAD');
    }

    /**
     * Returns the current git-revision date.
     */
    protected function getGitHeadDate(): string
    {
        return @exec('git show -s --format=%ci HEAD');
    }

    /**
     * Returns the current git-branch.
     */
    protected function getGitBranch(): string
    {
        return @exec('git rev-parse --abbrev-ref HEAD');
    }

    /**
     * Generates an SQL dump from the current app database and returns the path to the file.
     *
     * @throws FailedMysqlCommandException
     */
    protected function generateDump(array $options = []): ?string
    {
        if ($options['no-data'] ?? false) {
            $this->withoutData();
        }

        /** @var Connection $connection */
        $connection       = DB::connection($this->connectionName);
        $schemaState      = $connection->getSchemaState();
        $schemaStateProxy = $this->getProxyForSchemaState($schemaState);
        $tempFile         = tempnam('', 'protector');

        $schemaStateProxy->dump($connection, $tempFile);

        if (!filesize($tempFile)) {
            unlink($tempFile);

            $tempFile = null;
        }

        try {
            // Append some import/export-meta-data to the end.
            $metaData = sprintf(
                "\n-- options:%s\n-- meta:%s",
                json_encode($options, JSON_UNESCAPED_UNICODE),
                json_encode($this->getMetaData(), JSON_UNESCAPED_UNICODE)
            );

            file_put_contents($tempFile, $metaData, FILE_APPEND);
        } catch (Exception) {
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
        $metadata = $this->getMetaData();
        [$appUrl, $database, $connection, $date] = [
            parse_url(env('APP_URL'), PHP_URL_HOST),
            $metadata['database'] ?? '',
            $metadata['connection'] ?? '',
            $metadata['dumpedAtDate'],
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
     * Returns the existing Meta-Data for a new dump.
     */
    public function getMetaData(bool $refresh = false): array
    {
        if (!$refresh && $this->metaDataCache) {
            return $this->metaDataCache;
        }

        $gitInformation = [];

        if ($this->isUnderGitVersionControl()) {
            $this->guardExecEnabled();

            $gitInformation = [
                'gitRevision'     => $this->getGitRevision(),
                'gitBranch'       => $this->getGitBranch(),
                'gitRevisionDate' => $this->getGitHeadDate(),
            ];
        }

        return $this->metaDataCache = [
            'database'        => $this->connectionConfig['database'],
            'connection'      => $this->connectionName,
            'gitRevision'     => $gitInformation['gitRevision'] ?? null,
            'gitBranch'       => $gitInformation['gitBranch'] ?? null,
            'gitRevisionDate' => $gitInformation['gitRevisionDate'] ?? null,
            'dumpedAtDate'    => now(),
        ];
    }

    /**
     * Returns the last x lines from a file in correct order.
     */
    protected function tail(string $file, int $lines, int $buffer = 1024): array
    {
        // Open file-handle.
        $fileHandle = $this->getDisk()->readStream($file);
        // Jump to last character.
        fseek($fileHandle, 0, SEEK_END);

        $linesToRead = $lines;
        $contents    = '';

        // Only read file as long as file-pointer is not at start of the file and there are still lines to read open.
        while (ftell($fileHandle) && $linesToRead >= 0) {
            // Get the max length for reading, in case the buffer is longer than the remaining file-length.
            $seekLength = min(ftell($fileHandle), $buffer);

            // Set the pointer to a position in front of the current pointer.
            fseek($fileHandle, -$seekLength, SEEK_CUR);

            // Get the next content-chunk by using the according length.
            $contents = ($chunk = fread($fileHandle, $seekLength)).$contents;

            // Reset pointer to the position before reading the current chunk.
            fseek($fileHandle, -mb_strlen($chunk, 'UTF-8'), SEEK_CUR);

            // Decrease count of lines to read by the amount of new-lines given in the current chunk.
            $linesToRead -= substr_count($chunk, "\n");
        }

        // Get the last x lines from file.
        return array_slice(explode("\n", $contents), -$lines);
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
        string $connectionName = null
    ): Response|StreamedResponse {
        $shouldEncrypt = $this->shouldEncrypt();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$shouldEncrypt || $request->user()->tokenCan('protector:import')) {
            if ($this->withConnectionName($connectionName)) {
                try {
                    $serverFilePath = $this->createDump();
                    $publicKey      = $this->getPublicKey($request);
                } catch (InvalidConnectionException|FailedDumpGenerationException|InvalidConfigurationException $exception) {
                    return response($exception->getMessage(), 500, ['message' => $exception->getMessage()]);
                }

                $chunkSize = $this->getConfigValueForKey('chunkSize');

                return response()->streamDownload(
                    function () use ($publicKey, $request, $serverFilePath, $chunkSize, $shouldEncrypt) {
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
                        'Content-Type'    => 'text/plain',
                        'Pragma'          => 'no-cache',
                        'Expires'         => gmdate(DATE_RFC7231, time() - 3600),
                        // Encryption adds some overhead to the chunk, which has to be considered when decrypting it.
                        'Chunk-Size'      => $shouldEncrypt ? $chunkSize + $this->determineEncryptionOverhead(
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

            return;
        }

        if (in_array($destinationPath, $disk->files($this->getBaseDirectory()))) {
            throw new FailedCreatingDestinationPathException(
                sprintf('Could not create directory %s, because a file with the same name exists.', $destinationPath)
            );
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
            $request     = Http::withBasicAuth($credentials[0], $credentials[1]);
        } else {
            // Protector cannot be used without any authentication.
            throw new InvalidConfigurationException(
                'Either Laravel Sanctum has to be active or a htaccess login has to be defined.'
            );
        }

        return $request->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * Returns the name of the most recent dump.
     *
     * @throws FileNotFoundException
     */
    public function getLatestDumpName(): string
    {
        $disk          = $this->getDisk();
        $baseDirectory = $this->getConfigValueForKey('baseDirectory');
        $files         = $disk->files($baseDirectory);

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
                "There was an error decrypting the provided string. This might be due to mismatching crypto keys."
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
            $publicKey = sodium_hex2bin($request->user()->protector_public_key);
        } catch (SodiumException) {
            throw new InvalidConfigurationException(
                'There was an error receiving the crypto keys. This might be due to mismatching crypto keys.'
            );
        }

        return $publicKey;
    }

    public function createTempFilePath(string $diskFilePath): string|false
    {
        $tempFilePath = tempnam('', 'protector');
        $handle       = fopen($tempFilePath, 'w');
        $stream       = $this->getDisk()->readStream($diskFilePath);

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
     * Throws an exception if Exec is deactivated.
     *
     * @throws ShellAccessDeniedException
     */
    public function guardExecEnabled(): void
    {
        if (!function_exists('exec')) {
            throw new ShellAccessDeniedException();
        }
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
    ): void {
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
        $chunk          = str_repeat('0', $chunkSize);
        $encryptedChunk = sodium_crypto_box_seal($chunk, $publicKey);

        return strlen($encryptedChunk) - $chunkSize;
    }

    /**
     * @throws UnsupportedDatabaseException
     */
    protected function getProxyForSchemaState($schemaState): SchemaState
    {
        return match (get_class($schemaState)) {
            MySqlSchemaState::class => app(MySqlSchemaStateProxy::class, [$schemaState, $this]),
//            PostgresSchemaState::class => app('PostgresSchemaStateProxy', [$schemaState, $this]),
//            SqliteSchemaState::class => app('SqliteSchemaStateProxy', [$schemaState, $this]),
            default => throw new UnsupportedDatabaseException('Unsupported database schema state: '.class_basename($schemaState)),
        };
    }
}
