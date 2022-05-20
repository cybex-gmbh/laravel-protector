<?php

namespace Cybex\Protector;

use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Exception;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
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
    /**
     * Cache for the current connection-name.
     *
     * @var string
     */
    protected string $connection;

    /**
     * Cache for the current connection-configuration.
     *
     * @var mixed
     */
    protected mixed $connectionConfig;

    /**
     * Cache for the runtime-metadata for a new dump.
     *
     * @var array
     */
    protected array $cacheMetaData = [];

    /**
     * The name of the .env key for the Protector DB Token.
     *
     * @var string
     */
    protected string $authTokenKeyName = 'PROTECTOR_AUTH_TOKEN';

    /**
     * The name of the .env key for the Protector Private Key.
     *
     * @var string
     */
    protected string $privateKeyName = 'PROTECTOR_PRIVATE_KEY';

    /**
     * The server url for the dump endpoint.
     *
     * @var string
     */
    protected string $serverUrl = '';

    /**
     * The Protector Auth Token.
     *
     * @var string
     */
    protected string $authToken = '';

    public function __construct()
    {
        $this->configure();
    }

    /**
     * Configures the current instance based on the passed configuration or defaults.
     *
     * @param string|null $connectionName
     *
     * @return bool
     */
    public function configure(string $connectionName = null): bool
    {
        $this->connection = $connectionName ?? config('database.default');

        if (($this->connectionConfig = $this->getDatabaseConfig()) === false) {
            return false;
        }

        return true;
    }

    /**
     * Imports a specific SQL dump.
     *
     * @param string $sourceFilePath
     * @param array $options
     * @return bool
     *
     * @throws FileNotFoundException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     */
    public function importDump(string $sourceFilePath, array $options): bool
    {
        if (App::environment('production') && !($options['allow-production'])) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        if (!file_exists($sourceFilePath)) {
            throw new FileNotFoundException($sourceFilePath);
        }

        $success = true;

        try {
            $shellCommandDropCreateDatabase = sprintf('mysql -h%s -u%s -p%s -e %s 2> /dev/null',
                escapeshellarg($this->connectionConfig['host']),
                escapeshellarg($this->connectionConfig['username']),
                escapeshellarg($this->connectionConfig['password']),
                escapeshellarg(sprintf('drop database %1$s; create database %1$s;', $this->connectionConfig['database'])));

            $shellCommandImport = sprintf('mysql -h%s -u%s -p%s -D%s < %s 2> /dev/null',
                escapeshellarg($this->connectionConfig['host']),
                escapeshellarg($this->connectionConfig['username']),
                escapeshellarg($this->connectionConfig['password']),
                escapeshellarg($this->connectionConfig['database']),
                escapeshellarg($sourceFilePath));

            exec($shellCommandDropCreateDatabase);
            exec($shellCommandImport);

            if ($options['migrate']) {
                $output = new BufferedOutput;

                Artisan::call('migrate', [], $output);

                if (app()->runningInConsole()) {
                    echo $output->fetch();
                }
            }
        } catch (Exception) {
            $success = false;
        }

        return $success;
    }

    /**
     * Public function to create the relative file path for the dump.
     *
     * @param string $fileName
     * @return string
     */
    public function getDumpFilePath(string $fileName): string
    {
        return implode(DIRECTORY_SEPARATOR, array_filter([
            $this->getConfigValueForKey('baseDirectory'),
            $fileName,
        ]));
    }

    /**
     * Public function to create a dump for the given configuration.
     *
     * @param array $options
     * @return string
     *
     * @throws FailedDumpGenerationException
     * @throws InvalidConnectionException
     */
    public function createDump(array $options = []): string
    {
        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        if (!$serverFilePath = $this->generateDump($options)) {
            throw new FailedDumpGenerationException('Dump could not be created.');
        }

        return $serverFilePath;
    }

    /**
     * Returns the appended Meta-Data from a file.
     *
     * @param string $dumpFile
     *
     * @return array|bool
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
     * Deletes either all dumps or all old dumps on the client disk.
     *
     * @param string|null $sourceFilePath
     * @return void
     */
    public function flush(?string $sourceFilePath = null): void
    {
        $disk  = $this->getDisk();
        $files = $disk->files(config('protector.baseDirectory'));

        $sourceFilePath && $files = array_diff($files, [$sourceFilePath]);

        $disk->delete($files);
    }

    /**
     * Reads the remote dump file and stores it on the client disk.
     *
     * @return string
     *
     * @throws FailedRemoteDatabaseFetchingException
     * @throws InvalidConfigurationException
     * @throws InvalidEnvironmentException
     */
    public function getRemoteDump(): string
    {
        if (App::environment('production')) {
            throw new InvalidEnvironmentException('Retrieving a dump is not allowed on production systems.');
        }

        if ($this->isSanctumActive() && !$this->getPrivateKey()) {
            throw new InvalidConfigurationException('For using Laravel Sanctum a crypto keypair is required. There was none found in your .env file.');
        }

        if (!$serverUrl = $this->getServerUrl()) {
            throw new InvalidConfigurationException('Server url is not set or invalid.');
        }

        $this->createDirectory($this->getConfigValueForKey('baseDirectory'), $this->getDisk());

        $request = $this->getConfiguredHttpRequest();

        try {
            $response = $request->withoutRedirecting()->post($serverUrl);
        } catch (Exception $exception) {
            throw new FailedRemoteDatabaseFetchingException(sprintf('Could not fetch database from remote server: %s', $exception->getMessage()));
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

        $this->writeDumpFile($stream, $destinationFilePath, $response->header('Chunk-Size'), $response->header('Sanctum-Enabled'));

        $stream->close();

        return $destinationFilePath;
    }

    /**
     * Returns the current git-revision.
     *
     * @return string
     */
    public function getGitRevision(): string
    {
        return exec('git rev-parse HEAD');
    }

    /**
     * Returns the current git-revision date.
     *
     * @return string
     */
    public function getGitHeadDate(): string
    {
        return exec('git show -s --format=%ci HEAD');
    }

    /**
     * Returns the current git-branch.
     *
     * @return string
     */
    public function getGitBranch(): string
    {
        return exec('git rev-parse --abbrev-ref HEAD');
    }

    /**
     * Generates an SQL dump from the current app database and returns the path to the file.
     *
     * @param array $options
     * @return string|null
     */
    protected function generateDump(array $options = []): ?string
    {
        $dumpOptions = collect();
        $dumpOptions->push(sprintf('-h%s', escapeshellarg($this->connectionConfig['host'])));
        $dumpOptions->push(sprintf('-u%s', escapeshellarg($this->connectionConfig['username'])));
        $dumpOptions->push(sprintf('-p%s', escapeshellarg($this->connectionConfig['password'])));
        $dumpOptions->push(sprintf('--max-allowed-packet=%s', escapeshellarg(config('protector.maxPacketLength'))));
        $dumpOptions->push('--no-create-db');
        $dumpOptions->push('--set-gtid-purged=off');

        if ($options['no-data'] ?? false) {
            $dumpOptions->push('--no-data');
        }

        $dumpOptions->push(sprintf('%s', escapeshellarg($this->connectionConfig['database'])));

        $tempFile = tempnam('', 'protector');

        try {
            // Write dump using specific options.
            exec(sprintf('mysqldump %s > %s 2> /dev/null',
                $dumpOptions->implode(' '),
                escapeshellarg($tempFile)));

            // Append some import/export-meta-data to the end.
            $metaData = sprintf("\n-- options:%s\n-- meta:%s", json_encode($options, JSON_UNESCAPED_UNICODE), json_encode($this->getMetaData(), JSON_UNESCAPED_UNICODE));

            file_put_contents($tempFile, $metaData, FILE_APPEND);

            return $tempFile;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Returns the database name specified in the connectionConfig array.
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        return $this->connectionConfig['database'];
    }

    /**
     * Returns the database config for the given connection.
     *
     * @return mixed
     */
    protected function getDatabaseConfig(): mixed
    {
        return config(sprintf('database.connections.%s', $this->connection), false);
    }

    /**
     * Creates a filename for the dump file.
     *
     * @return string
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

        return sprintf(config('protector.fileName'), $appUrl, $database, $connection, $date->year, $date->month, $date->day, $date->hour, $date->minute, $date->second);
    }

    /**
     * Returns the existing Meta-Data for a new dump.
     *
     * @param bool $refresh
     *
     * @return array
     */
    protected function getMetaData(bool $refresh = false): array
    {
        if (!$refresh && $this->cacheMetaData) {
            return $this->cacheMetaData;
        }

        return $this->cacheMetaData = [
            'database'        => $this->connectionConfig['database'],
            'connection'      => $this->connection,
            'gitRevision'     => $this->getGitRevision(),
            'gitBranch'       => $this->getGitBranch(),
            'gitRevisionDate' => $this->getGitHeadDate(),
            'dumpedAtDate'    => now(),
        ];
    }

    /**
     * Returns the last x lines from a file in correct order.
     *
     * @param string $file
     * @param int $lines
     * @param int $buffer
     *
     * @return array
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
            $contents = ($chunk = fread($fileHandle, $seekLength)) . $contents;

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
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    protected function getConfigValueForKey(string $key, mixed $default = null): mixed
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Returns the config value for the baseDirectory key.
     *
     * @return mixed
     */
    public function getBaseDirectory(): mixed
    {
        return $this->getConfigValueForKey('baseDirectory');
    }

    /**
     * Prepares the file download response.
     * Prevents the exposure of the connectionName parameter to routing.
     *
     * @param Request $request
     *
     * @return Response|StreamedResponse|Application|ResponseFactory
     */
    public function prepareFileDownloadResponse(Request $request): Response|StreamedResponse|Application|ResponseFactory
    {
        return $this->generateFileDownloadResponse($request);
    }

    /**
     * Generates a response which allows downloading the dump file.
     *
     * @param Request $request
     * @param string|null $connectionName
     *
     * @return Application|ResponseFactory|Response|StreamedResponse
     */
    public function generateFileDownloadResponse(Request $request, string $connectionName = null): Response|StreamedResponse|Application|ResponseFactory
    {
        $sanctumIsActive = $this->isSanctumActive();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$sanctumIsActive || $request->user()->tokenCan('protector:import')) {
            if ($this->configure($connectionName)) {

                try {
                    $serverFilePath = $this->createDump();
                    $publicKey = $this->getPublicKey($request);
                } catch (InvalidConnectionException|FailedDumpGenerationException|InvalidConfigurationException $exception) {
                    return response('', 500, ['message' => $exception->getMessage()]);
                }

                $chunkSize = $this->getConfigValueForKey('chunkSize');

                return response()->streamDownload(
                    function () use ($publicKey, $request, $serverFilePath, $chunkSize, $sanctumIsActive) {
                        $inputHandle = fopen($serverFilePath, 'rb');

                        while (!feof($inputHandle)) {
                            $chunk = fread($inputHandle, $chunkSize);

                            // Encrypt the data when Laravel Sanctum is active.
                            if ($sanctumIsActive) {
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
                        'Expires'         => gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT',
                        // Encryption adds some overhead to the chunk, which has to be considered when decrypting it.
                        'Chunk-Size'      => $sanctumIsActive ? $chunkSize + $this->determineEncryptionOverhead(
                                $chunkSize,
                                $publicKey
                            ) : $chunkSize,
                        'Sanctum-Enabled' => $sanctumIsActive,
                    ]
                );
            }
        }

        throw new UnauthorizedHttpException('', 'Unauthorized');
    }

    /**
     * Returns the disk which is stated in the config. If no disk is stated the default filesystem disk will be returned.
     *
     * @param string|null $diskName
     * @return FilesystemAdapter
     */
    public function getDisk(?string $diskName = null): FilesystemAdapter
    {
        return Storage::disk($this->getDiskName($diskName));
    }

    /**
     * @param string|null $diskName
     * @return string
     */
    public function getDiskName(?string $diskName = null): string
    {
        return $diskName ?? $this->getConfigValueForKey('diskName', config('filesystems.default'));
    }

    /**
     * Creates a directory at the given path, if it doesn't exist already.
     *
     * @param string|null $destinationPath
     * @param FilesystemAdapter $disk
     * @return void
     * @throws FailedCreatingDestinationPathException
     */
    protected function createDirectory(?string $destinationPath, FilesystemAdapter $disk): void
    {
        if ($disk->missing($destinationPath)) {
            if ($disk->makeDirectory($destinationPath) === false) {
                throw new FailedCreatingDestinationPathException(sprintf('Could not create the non-existing destination path %s on disk %s.', $destinationPath, $this->getDiskName()));
            }
        }
    }

    /**
     * Configure Http request with either the sanctum token or htaccess credentials.
     *
     * @return PendingRequest
     * @throws InvalidConfigurationException
     */
    protected function getConfiguredHttpRequest(): PendingRequest
    {
        $htaccessLogin = $this->getConfigValueForKey('remoteEndpoint.htaccessLogin');

        if ($this->isSanctumActive()) {
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
            throw new InvalidConfigurationException('Either Laravel Sanctum has to be active or a htaccess login has to be defined.');
        }

        return $request->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * Sets the name of the .env key for the Protector DB Token.
     *
     * @param string $authTokenKeyName
     */
    public function setAuthTokenKeyName(string $authTokenKeyName): void
    {
        $this->authTokenKeyName = $authTokenKeyName;
    }

    /**
     * Gets the name of the .env key for the Protector DB Token.
     *
     * @return string
     */
    public function getAuthTokenKeyName(): string
    {
        return $this->authTokenKeyName;
    }

    /**
     * Sets the name of the .env key for the Protector Crypto Key.
     *
     * @param string $privateKeyName
     */
    public function setPrivateKeyName(string $privateKeyName): void
    {
        $this->privateKeyName = $privateKeyName;
    }

    /**
     * Sets the name of the .env key for the Protector Crypto Key.
     *
     * @return string
     */
    public function getPrivateKeyName(): string
    {
        return $this->privateKeyName;
    }

    /**
     * Retrieves the private key for Sodium encryption.
     *
     * @return string
     */
    protected function getPrivateKey(): string
    {
        return env($this->privateKeyName, '');
    }

    /**
     * Retrieves the auth token for Laravel Sanctum authentication.
     *
     * @return string
     */
    protected function getAuthToken(): string
    {
        return $this->authToken ?: env($this->authTokenKeyName, '');
    }

    /**
     * Sets the auth token for Laravel Sanctum authentication.
     *
     * @param string $authToken
     */
    public function setAuthToken(string $authToken): void
    {
        $this->authToken = $authToken;
    }

    /**
     * Retrieves the server url of the dump endpoint.
     *
     * @return string
     */
    public function getServerUrl(): string
    {
        return $this->serverUrl ?: $this->getConfigValueForKey('remoteEndpoint.serverUrl');
    }

    /**
     * Sets the server url of the dump endpoint.
     *
     * @param string $serverUrl
     */
    public function setServerUrl(string $serverUrl): void
    {
        $this->serverUrl = $serverUrl;
    }

    /**
     * Returns the name of the most recent dump.
     *
     * @return string
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
     * @param string $encryptedString
     *
     * @return string
     * @throws InvalidConfigurationException
     */
    public function decryptString(string $encryptedString): string
    {
        $decryptedString = sodium_crypto_box_seal_open($encryptedString, sodium_hex2bin($this->getPrivateKey()));

        if ($decryptedString === false) {
            throw new InvalidConfigurationException("There was an error decrypting the provided string. This might be due to mismatching crypto keys.");
        }

        return $decryptedString;
    }

    /**
     * @param Request $request
     * @return string
     * @throws InvalidConfigurationException
     */
    function getPublicKey(Request $request): string
    {
        try {
            $publicKey = sodium_hex2bin($request->user()->protector_public_key);
        } catch (SodiumException) {
            throw new InvalidConfigurationException('There was an error receiving the crypto keys. This might be due to mismatching crypto keys.');
        }

        return $publicKey;
    }

    /**
     * @param string $diskFilePath
     * @return false|string
     */
    public function createTempFilePath(string $diskFilePath): string|false
    {
        $tempFilePath = tempnam('', 'protector');
        $handle = fopen($tempFilePath, 'w');
        $stream = $this->getDisk()->readStream($diskFilePath);

        stream_copy_to_stream($stream, $handle);

        fclose($handle);
        return $tempFilePath;
    }

    /**
     * Removes the specified temp file.
     *
     * @param string $sourceFilePath
     * @return void
     */
    public function deleteTempFile(string $sourceFilePath): void
    {
        unlink($sourceFilePath);
    }

    /**
     * @return bool
     */
    protected function shouldEncrypt(): bool
    {
        return $this->isSanctumActive();
    }

    /**
     * Returns whether the Sanctum middleware is activated in the config.
     *
     * @return bool
     */
    protected function isSanctumActive(): bool
    {
        return in_array('auth:sanctum', config('protector.routeMiddleware'));
    }

    /**
     * Returns the destination file path for the database dump.
     *
     * @param string $fileName
     *
     * @return string
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
     *
     * @param StreamInterface $stream
     * @param string          $destinationFilePath
     * @param int             $chunkSize
     * @param bool            $sanctumEnabled
     *
     * @return void
     */
    protected function writeDumpFile(StreamInterface $stream, string $destinationFilePath, int $chunkSize, bool $sanctumEnabled): void
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

    /**
     * @param int    $chunkSize
     * @param string $publicKey
     *
     * @return int
     */
    private function determineEncryptionOverhead(int $chunkSize, string $publicKey): int
    {
        $chunk          = str_repeat('0', $chunkSize);
        $encryptedChunk = sodium_crypto_box_seal($chunk, $publicKey);

        return strlen($encryptedChunk) - $chunkSize;
    }
}
