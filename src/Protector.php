<?php

namespace Cybex\Protector;

use Cybex\Protector\Exceptions\FailedDumpGenerationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use League\Flysystem\FileNotFoundException;
use SplFileObject;

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
     * @throws \Exception
     */
    public function importDump(string $sourceFilePath, array $options): bool
    {
        // Production environment is not allowed unless set in options.
        if (App::environment('production') && !($options['allow-production'] ?? false)) {
            throw new InvalidEnvironmentException('Production environment is not allowed and option was not set.');
        }

        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly');
        }

        if (!file_exists($sourceFilePath)) {
            throw new FileNotFoundException($sourceFilePath);
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
                escapeshellarg($sourceFilePath));

            exec($shellCommandDropCreateDatabase);
            exec($shellCommandImport);

            if ($options['run-migrations'] ?? false) {
                Artisan::call('migrate');
            }

            return true;
        } catch (\Exception $exception) {
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
    public function createDump(string $fileName = null, array $options = []): string
    {
        if (!$this->connectionConfig) {
            throw new InvalidConnectionException('Connection is not configured properly.');
        }

        $destinationFileName = $fileName ?? $this->createFilename();

        $destinationFilePath = sprintf('%s%s%s', $this->getConfigValueForKey('dumpPath'), DIRECTORY_SEPARATOR, $destinationFileName);

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
     * @return array
     */
    public function getRemoteDump(): array
    {
        if (App::environment('production')) {
            return [false, sprintf('Retrieving a dump is not allowed on production systems.')];
        }

        $serverUrl               = $this->getConfigValueForKey('remoteEndpoint.serverUrl');
        $htaccessLogin           = $this->getConfigValueForKey('remoteEndpoint.htaccessLogin');
        $destinationPath         = $this->getConfigValueForKey('dumpPath');

        // Create destination dir if it does not exist.
        if (!is_dir($destinationPath)) {
            if (mkdir($destinationPath, 0777, true) === false) {
                return [false, sprintf('Could not create the non-existing destination path %s.', $destinationPath)];
            }
        }

        $dumpApiCall = curl_init($serverUrl);

        if ($htaccessLogin) {
            curl_setopt($dumpApiCall, CURLOPT_USERPWD, $htaccessLogin);
        }

        curl_setopt($dumpApiCall, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($dumpApiCall, CURLOPT_POST, 1);
        curl_setopt($dumpApiCall, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($dumpApiCall, CURLOPT_FAILONERROR, true);
        curl_setopt($dumpApiCall,CURLOPT_HEADER, 1);

        $curlResult = curl_exec($dumpApiCall);
        $httpCode   = curl_getinfo($dumpApiCall, CURLINFO_HTTP_CODE);

        $header_size = curl_getinfo($dumpApiCall, CURLINFO_HEADER_SIZE);
        $header = substr($curlResult, 0, $header_size);
        $body = substr($curlResult, $header_size);

        // Get remote filename from header.
        $headers = explode("\r\n", $header);
        foreach($headers as $entry) {
            if (preg_match('/filename="(?P<filename>.+)"/i', $entry, $matches)) {
                $destinationFilename = $matches['filename'];
            }
        }

        $fullDestinationFilename = $destinationPath . DIRECTORY_SEPARATOR . ($destinationFilename ?? 'remote_dump.sql');

        // By doing it this way you don't need to make a separate Head-Request, but the data gets loaded into the RAM.
        file_put_contents($fullDestinationFilename, $body);

        if ($curlResult === false) {
            $curlError = curl_error($dumpApiCall);

            return [false, sprintf('Could not fetch database from remote server: %s (HTTP %s).', $curlError, $httpCode)];
        }

        curl_close($dumpApiCall);

        return [true, sprintf('Successfully retrieved remote dump from %s (HTTP %s).', $serverUrl, $httpCode), $fullDestinationFilename];
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

        try {
            // Write dump using specific options.
            exec(sprintf('mysqldump %s > %s 2> /dev/null',
                $dumpOptions->implode(' '),
                escapeshellarg($destinationFilePath)));

            // Append some import/export-meta-data to the end.
            $metaData = sprintf("-- options:%s\n-- meta:%s", json_encode($options, JSON_UNESCAPED_UNICODE), json_encode($this->getMetaData(), JSON_UNESCAPED_UNICODE));
            file_put_contents($destinationFilePath, $metaData, FILE_APPEND);

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * Returns the database config for the given connection.
     *
     * @return \Illuminate\Config\Repository|bool
     */
    protected function getDatabaseConfig()
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
            $appUrl = parse_url(env('APP_URL'), PHP_URL_HOST),
            $metadata['database'] ?? '',
            $metadata['connection'] ?? '',
            Arr::get($metadata, 'dumpedAtDate.year', '0000'),
            Arr::get($metadata, 'dumpedAtDate.mon', '00'),
            Arr::get($metadata, 'dumpedAtDate.mday', '00'),
            Arr::get($metadata, 'dumpedAtDate.hours', '00'),
            Arr::get($metadata, 'dumpedAtDate.minutes', '00'),
        ];

        return sprintf(config('protector.fileName'), $appUrl, $database, $connection, $year, $month, $day, $hour, $minute);
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
        // Open file-handle using spl.
        $fileHandle = new SplFileObject($file);
        // Jump to last character.
        $fileHandle->fseek(0, SEEK_END);

        $linesToRead = $lines;
        $contents    = '';

        // Only read file as long as file-pointer is not at start of the file and there are still lines to read open.
        while ($fileHandle->ftell() && $linesToRead >= 0) {
            // Get the max length for reading, in case the buffer is longer than the remaining file-length.
            $seekLength = min($fileHandle->ftell(), $buffer);

            // Set the pointer to a position in front of the current pointer.
            $fileHandle->fseek(-$seekLength, SEEK_CUR);

            // Get the next content-chunk by using the according length.
            $contents = ($chunk = $fileHandle->fread($seekLength)) . $contents;

            // Reset pointer to the position before reading the current chunk.
            $fileHandle->fseek(-mb_strlen($chunk, 'UTF-8'), SEEK_CUR);

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
     *
     * @return string
     */
    protected function getConfigValueForKey(string $key): string
    {
        $value = config(sprintf('protector.%s', $key));

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Generates a response which allows downloading the dump file.
     *
     * @param string $connectionName
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response|null
     */
    public function generateFileDownloadResponse(string $connectionName = null)
    {
        if ($this->configure($connectionName)) {
            $fullPath = $this->createDump();
            $fileData = file_get_contents($fullPath, false);
            $fileSize = filesize($fullPath);
            $fileName = basename($fullPath);
            File::delete($fullPath);
            return response($fileData)
                ->withHeaders([
                    'Content-Type'        => 'text/plain',
                    'Pragma'              => 'no-cache',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                    'Content-Length'      => $fileSize,
                    'Expires'             => gmdate('D, d M Y H:i:s', time()-3600) . ' GMT',
                ]);
        }
        return null;
    }
}
