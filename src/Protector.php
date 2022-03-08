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
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Psr\Http\Message\StreamInterface;
use Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Illuminate\Support\Facades\Http;

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
    protected array $cacheMetaData;

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
     * @param array  $options
     *
     * @return bool
     *
     * @throws InvalidEnvironmentException
     * @throws InvalidConnectionException
     * @throws FileNotFoundException
     * @throws Exception
     */
    public function importDump(string $sourceFilePath, array $options): bool
    {
        // Production environment is not allowed unless set in options.
        if (App::environment('production') && !($options['allow-production'])) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        if (!$this->getDisk()->exists($sourceFilePath)) {
            throw new FileNotFoundException($sourceFilePath);
        }

        // Getting a local copy because disk files might not be possible to import.
        Storage::disk('local')->put($sourceFilePath, $this->getDisk()->get($sourceFilePath));
        $filePath = Storage::disk('local')->path($sourceFilePath);

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
                escapeshellarg($filePath));

            exec($shellCommandDropCreateDatabase);
            exec($shellCommandImport);

            if ($options['migrate']) {
                $output = new BufferedOutput;

                Artisan::call('migrate', [], $output);

                if (app()->runningInConsole()) {
                    echo $output->fetch();
                }
            }

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Public function to create a dump for the given configuration.
     *
     * @param string $fileName
     * @param array  $options
     *
     * @return string
     *
     * @throws FailedDumpGenerationException
     * @throws InvalidConnectionException
     */
    public function createDump(string $fileName, array $options = []): string
    {
        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        $destinationFilePath = sprintf('%s%s%s', $this->getConfigValueForKey('baseDirectory'), DIRECTORY_SEPARATOR, $fileName);

        if (!$this->generateDump($destinationFilePath, $options)) {
            throw new FailedDumpGenerationException('Error while creating the dump.');
        }

        return $destinationFilePath;
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

        // Create dump directory if it doesn't exist yet.
        $this->createDirectory($this->getDisk()->path($this->getConfigValueForKey('baseDirectory')));

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
     * @param string $destinationFilePath
     * @param array  $options
     *
     * @return bool
     */
    protected function generateDump(string $destinationFilePath, array $options = []): bool
    {
        $dumpOptions = collect();
        $dumpOptions->push(sprintf('-h%s', escapeshellarg($this->connectionConfig['host'])));
        $dumpOptions->push(sprintf('-u%s', escapeshellarg($this->connectionConfig['username'])));
        $dumpOptions->push(sprintf('-p%s', escapeshellarg($this->connectionConfig['password'])));
        $dumpOptions->push(sprintf('--max-allowed-packet=%s', escapeshellarg(config('protector.maxPacketLength'))));
        $dumpOptions->push('--no-create-db');

        if ($options['no-data'] ?? false) {
            $dumpOptions->push('--no-data');
        }

        $dumpOptions->push(sprintf('%s', escapeshellarg($this->connectionConfig['database'])));

        $this->createDirectory(Storage::disk('local')->path($this->getConfigValueForKey('baseDirectory')));

        try {
            // Write dump using specific options.
            exec(sprintf('mysqldump %s > %s 2> /dev/null',
                $dumpOptions->implode(' '),
                escapeshellarg(Storage::disk('local')->path($destinationFilePath))));

            $this->getDisk()->put($destinationFilePath, Storage::disk('local')->get($destinationFilePath));
            // Append some import/export-meta-data to the end.
            $metaData = sprintf("-- options:%s\n-- meta:%s", json_encode($options, JSON_UNESCAPED_UNICODE), json_encode($this->getMetaData(), JSON_UNESCAPED_UNICODE));
            $this->getDisk()->append($destinationFilePath, $metaData);

            return true;
        } catch (Exception) {
            return false;
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
     * @return Repository|Application|mixed
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
        [$appUrl, $database, $connection, $year, $month, $day, $hour, $minute,] = [
            parse_url(env('APP_URL'), PHP_URL_HOST),
            $metadata['database'] ?? '',
            $metadata['connection'] ?? '',
            Arr::get($metadata, 'dumpedAtDate.year', '0000'),
            Arr::get($metadata, 'dumpedAtDate.mon', '00'),
            Arr::get($metadata, 'dumpedAtDate.mday', '00'),
            Arr::get($metadata, 'dumpedAtDate.hours', '00'),
            Arr::get($metadata, 'dumpedAtDate.minutes', '00'),
        ];

        return sprintf(config('protector.fileName'), $appUrl, $database, $connection, $year, $month, $day, $hour, $minute, uniqid());
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

        $gitRevision     = $this->getGitRevision();
        $gitBranch       = $this->getGitBranch();
        $gitRevisionDate = $this->getGitHeadDate();

        return $this->cacheMetaData = [
            'database'        => $this->connectionConfig['database'],
            'connection'      => $this->connection,
            'gitRevision'     => $gitRevision,
            'gitBranch'       => $gitBranch,
            'gitRevisionDate' => $gitRevisionDate,
            'dumpedAtDate'    => getdate(),
        ];
    }

    /**
     * Returns the last x lines from a file in correct order.
     *
     * @param string $file
     * @param int    $lines
     * @param int    $buffer
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
     * @param null   $default
     *
     * @return string|null
     */
    protected function getConfigValueForKey(string $key, $default = null): ?string
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Prepares the file download response.
     * Prevents the exposure of the connectionName parameter to routing.
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function prepareFileDownloadResponse(Request $request): StreamedResponse
    {
        return $this->generateFileDownloadResponse($request);
    }

    /**
     * Generates a response which allows downloading the dump file.
     *
     * @param Request     $request
     * @param string|null $connectionName
     *
     * @return StreamedResponse
     */
    public function generateFileDownloadResponse(Request $request, string $connectionName = null): StreamedResponse
    {
        $sanctumIsActive = $this->isSanctumActive();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$sanctumIsActive || $request->user()->tokenCan('protector:import')) {
            if ($this->configure($connectionName)) {
                $fullFileName = $this->createFilename($this->shouldEncrypt());
                $relativePath = $this->createDump($fullFileName);
                $fileName     = basename($relativePath);
                $localDisk    = Storage::disk('local');

                $chunkSize = $this->getConfigValueForKey('chunkSize');

                return response()->streamDownload(
                    function () use ($request, $localDisk, $relativePath, $chunkSize, $sanctumIsActive) {
                        $inputHandle = fopen($localDisk->path($relativePath), 'rb');

                        while (!feof($inputHandle)) {
                            $chunk = fread($inputHandle, $chunkSize);

                            // Encrypt the data when Laravel Sanctum is active.
                            if ($sanctumIsActive) {
                                $publicKey = $request->user()->protector_public_key;
                                $chunk     = sodium_crypto_box_seal($chunk, sodium_hex2bin($publicKey));
                            }

                            echo $chunk;
                        }

                        fclose($inputHandle);
                        $localDisk->delete($relativePath);
                    }, $fileName, [
                    'Content-Type'    => 'text/plain',
                    'Pragma'          => 'no-cache',
                    'Expires'         => gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT',
                    // Encryption adds some overhead to the chunk, which has to be considered when decrypting it.
                    'Chunk-Size'      => $sanctumIsActive ? $chunkSize + $this->determineEncryptionOverhead($chunkSize, $request->user()->protector_public_key) : $chunkSize,
                    'Sanctum-Enabled' => $sanctumIsActive,
                ]);
            }
        }

        throw new UnauthorizedHttpException('', 'Unauthorized');
    }

    /**
     * Returns the disk which is stated in the config. If no disk is stated the default filesystem disk will be returned.
     *
     * @return FilesystemAdapter
     */
    public function getDisk(): FilesystemAdapter
    {
        return Storage::disk($this->getConfigValueForKey('diskName', config('filesystems.default')));
    }

    /**
     * Creates a directory at the given path, if it doesn't exist already.
     *
     * @param string|null $destinationPath
     *
     * @throws FailedCreatingDestinationPathException
     */
    protected function createDirectory(?string $destinationPath): void
    {
        if (!is_dir($destinationPath)) {
            if (mkdir($destinationPath, 0777, true) === false) {
                throw new FailedCreatingDestinationPathException(sprintf('Could not create the non-existing destination path %s.', $destinationPath));
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
        $encryptedChunk = sodium_crypto_box_seal($chunk, sodium_hex2bin($publicKey));

        return strlen($encryptedChunk) - $chunkSize;
    }
}
