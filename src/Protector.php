<?php

namespace Cybex\Protector;

use Cybex\Protector\Exceptions\FailedCreatingDestinationPathException;
use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\FailedRemoteDatabaseFetchingException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\UnauthorizedException;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use League\Flysystem\FileNotFoundException;
use Storage;

class Protector
{
    /**
     * Cache for the current connection-name.
     *
     * @var
     */
    protected $connection;

    /**
     * Cache for the current connection-configuration.
     *
     * @var
     */
    protected $connectionConfig;

    /**
     * Cache for the runtime-metadata for a new dump.
     *
     * @var
     */
    protected $cacheMetaData;

    /**
     * The name of the .env key for the Protector DB Token.
     *
     * @var
     */
    protected $authTokenKeyName = 'PROTECTOR_AUTH_TOKEN';

    /**
     * The name of the .env key for the Protector Private Key.
     *
     * @var
     */
    protected $privateKeyName = 'PROTECTOR_PRIVATE_KEY';

    /**
     * The server url for the dump endpoint.
     *
     * @var
     */
    protected $serverUrl = '';

    /**
     * The Protector Auth Token.
     *
     * @var
     */
    protected $authToken = '';

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
        $tempFile = '';

        if (File::extension($filePath) === 'protector') {
            $tempFile = tempnam(sys_get_temp_dir(), '');

            file_put_contents($tempFile, $this->decryptDump(file_get_contents($filePath)));
        }

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
                escapeshellarg($tempFile ?: $filePath));

            exec($shellCommandDropCreateDatabase);
            exec($shellCommandImport);

            if ($options['migrate'] ?? false) {
                Artisan::call('migrate');
            }

            if ($tempFile) {
                File::delete($tempFile);
            }

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Public function to create a dump for the given configuration.
     *
     * @param string|null $fileName
     * @param array       $options
     *
     * @return string
     *
     * @throws InvalidConnectionException
     * @throws FailedDumpGenerationException
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
     * Returns the appended Meta-Data from a file
     *
     * @param string $dumpFile
     *
     * @return array|bool
     */
    public function getDumpMetaData(string $dumpFile)
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
     * @throws UnauthorizedException
     */
    public function getRemoteDump(): string
    {
        if (App::environment('production')) {
            throw new InvalidEnvironmentException(sprintf('Retrieving a dump is not allowed on production systems.'));
        }

        $serverUrl = $this->getServerUrl();

        if ($this->isSanctumActive() && !$this->getPrivateKey()) {
            throw new InvalidConfigurationException('For using Laravel Sanctum a crypto keypair is required. There was none found in your .env file.');
        }

        if (!$serverUrl) {
            throw new InvalidConfigurationException('Server url is not set or invalid');
        }

        // Get and configure the HTTP Request with either the Laravel Sanctum Token or Htaccess.
        $request = $this->getConfiguredHttpRequest();

        try {
            $response = $request->withoutRedirecting()->post($serverUrl);
        } catch (Exception $exception) {
            throw new FailedRemoteDatabaseFetchingException(sprintf('Could not fetch database from remote server: %s', $exception->getMessage()));
        }

        $httpCode = $response->status();

        if ($httpCode != 200) {
            throw new UnauthorizedException('Unauthorized access');
        }

        $body = $response->body();

        // Get remote filename from header.
        $contentDispositionHeader = $response->header('Content-Disposition');

        if (preg_match('/filename="(?P<filename>.+)"/i', $contentDispositionHeader, $matches)) {
            $destinationFilename = $matches['filename'];
        }

        $disk                = $this->getDisk();
        $destinationFilepath = sprintf(
            '%s%s%s',
            $this->getConfigValueForKey('baseDirectory'),
            DIRECTORY_SEPARATOR,
            ($destinationFilename ?? 'remote_dump.sql')
        );

        $disk->put($destinationFilepath, $body);

        return $destinationFilepath;
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
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Returns the database config for the given connection.
     *
     * @return Repository|bool
     */
    protected function getDatabaseConfig()
    {
        return config(sprintf('database.connections.%s', $this->connection), false);
    }

    /**
     * Creates a filename for the dump file.
     *
     * @param bool $encrypted
     *
     * @return string
     */
    public function createFilename(bool $encrypted = false): string
    {
        $metadata = $this->getMetaData();
        [$appUrl, $database, $connection, $year, $month, $day, $hour, $minute,] = [
            $appUrl = parse_url(env('APP_URL'), PHP_URL_HOST),
            $metadata['database'] ?? '',
            $metadata['connection'] ?? '',
            Arr::get($metadata, 'dumpedAtDate.year', '0000'),
            Arr::get($metadata, 'dumpedAtDate.mon', '00'),
            Arr::get($metadata, 'dumpedAtDate.mday', '00'),
            Arr::get($metadata, 'dumpedAtDate.hours', '00'),
            Arr::get($metadata, 'dumpedAtDate.minutes', '00'),
        ];

        return sprintf(config('protector.fileName') . '.%9$s', $appUrl, $database, $connection, $year, $month, $day, $hour, $minute, $encrypted ? 'protector' : 'sql');
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
     * @return string
     */
    protected function getConfigValueForKey(string $key, $default = null): ?string
    {
        $value = config(sprintf('protector.%s', $key), $default);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Prepares the file download response for the dump by extracting the user.
     *
     * @param Request $request
     *
     * @return ResponseFactory|Response|null
     */
    public function prepareFileDownloadResponse(Request $request) {
        return $this->generateFileDownloadResponse($request->user());
    }

    /**
     * Generates a response which allows downloading the dump file.
     *
     * @param AuthUser    $user
     * @param string|null $connectionName
     * @param bool        $disableTokenCheck
     *
     * @return ResponseFactory|Response|null
     */
    public function generateFileDownloadResponse(AuthUser $user, string $connectionName = null, bool $disableTokenCheck = false)
    {
        if (!is_a($user, config('auth.providers.users.model'))) {
            return response()->json('Unknown user class', 401);
        }

        $sanctumIsActive = $this->isSanctumActive();

        // Only proceed when either Laravel Sanctum is turned off or the user's token is valid.
        if (!$sanctumIsActive || $disableTokenCheck || $user->tokenCan('protector:import')) {
            if ($this->configure($connectionName)) {
                $fullFileName = $this->createFilename($this->shouldEncrypt());
                $relativePath = $this->createDump($fullFileName);
                $fileName     = basename($relativePath);
                $localDisk    = Storage::disk('local');

                // Encrypt the data when Laravel Sanctum is active.
                if ($sanctumIsActive) {
                    $publicKey = $user->protector_public_key;
                    $fileData  = sodium_crypto_box_seal($localDisk->get($relativePath), sodium_hex2bin($publicKey));
                    $fileSize  = mb_strlen($fileData, '8bit');
                } else {
                    $fileData = $localDisk->get($relativePath);
                    $fileSize = $localDisk->size($relativePath);
                }

                $localDisk->delete($relativePath);

                return response($fileData)
                    ->withHeaders([
                        'Content-Type'        => 'text/plain',
                        'Pragma'              => 'no-cache',
                        'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                        'Content-Length'      => $fileSize,
                        'Expires'             => gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT',
                    ]);
            }
        }

        return response()->json('Unauthorized', 401);
    }

    /**
     * Returns the disk which is stated in the config. If no disk is stated the default filesystem disk will be returned.
     *
     * @return Illuminate\Filesystem\FilesystemAdapter
     */
    public function getDisk()
    {
        return Storage::disk($this->getConfigValueForKey('diskName', config('filesystems.default')));
    }

    /**
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
     * @return PendingRequest
     * @throws InvalidConfigurationException
     */
    protected function getConfiguredHttpRequest(): PendingRequest
    {
        $htaccessLogin = $this->getConfigValueForKey('remoteEndpoint.htaccessLogin');

        if ($this->isSanctumActive()) {
            if ($htaccessLogin) {
                throw new InvalidConfigurationException('Laravel Sanctum and Htaccess can not be used simultaneously');
            }

            $request = Http::withToken($this->getAuthToken());
        } elseif ($htaccessLogin) {
            $credentials = explode(':', $htaccessLogin);
            $request     = Http::withBasicAuth($credentials[0], $credentials[1]);
        } else {
            throw new InvalidConfigurationException('Either Laravel Sanctum has to be active or a htaccess login has to be defined.');
        }

        return $request;
    }

    /**
     * Sets the name of the .env key for the Protector DB Token.
     *
     * @param $authTokenKeyName
     */
    public function setAuthTokenKeyName($authTokenKeyName): void
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
    public function setPrivateKeyName($privateKeyName): void
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
     * @param $body
     *
     * @return false|string
     * @throws InvalidConfigurationException
     */
    public function decryptDump($body)
    {
        $body = sodium_crypto_box_seal_open($body, sodium_hex2bin($this->getPrivateKey()));

        if ($body === false) {
            throw new InvalidConfigurationException("There was an error decrypting the database dump. This might be due to mismatching crypto keys.");
        }

        return $body;
    }

    /**
     * @return bool
     */
    protected function shouldEncrypt(): bool
    {
        return $this->isSanctumActive();
    }

    /**
     * @return bool
     */
    protected function isSanctumActive(): bool
    {
        return in_array('auth:sanctum', config('protector.routeMiddleware'));
    }
}
