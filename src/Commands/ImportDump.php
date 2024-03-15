<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * Class ImportDump
 */
class ImportDump extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:import
                {--d|dump= : The file name in the database dump folder. }
                {--f|file= : The absolute path and filename to the dump to import. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--force : Forces the import of the given file or remote download. Requires the dump, file or remote option. }
                {--i|ignore-connection-filter : Ignores filter of dumps to defined connections. }
                {--r|remote : Pull a fresh dump from the remote server as configured in the .env file. Will be used as fallback when combined with other options. }
                {--flush : Delete all existing dumps in the dump folder when using a remote dump. }
                {--l|latest : Import the most recent dump available in the configured dumps directory. }
                {--m|migrate : Run database migrations after import. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports a local or remote database dump.';

    protected const DOWNLOAD_REMOTE_DUMP = 'Download remote dump';
    protected const IMPORT_EXISTING_LOCAL_DUMP = 'Import existing local dump';

    protected ?Protector $protector = null;

    /**
     * Execute the console command.
     *
     * @throws InvalidEnvironmentException
     */
    public function handle(): int
    {
        $this->protector = app('protector');

        $this->protector->guardExecEnabled();

        $optionDump = $this->option('dump');
        $optionFile = $this->option('file');
        $optionRemote = $this->option('remote');
        $optionForce = $this->option('force');
        $optionLatest = $this->option('latest');
        $optionConnection = $this->option('connection');

        if (App::environment('production') && !$this->option('allow-production')) {
            throw new InvalidEnvironmentException(
                'Import is not allowed on production systems! Use --allow-production'
            );
        }

        if ($optionForce && !($optionRemote || $optionFile || $optionDump || $optionLatest)) {
            $this->error('Nothing to import.');

            return self::FAILURE;
        }

        $this->protector->withConnectionName($optionConnection);

        $shouldImportLocalDump = $optionFile || $optionDump || $optionLatest;
        $shouldDownloadDump = $optionRemote || (!$shouldImportLocalDump && $this->userWantsRemoteDump());

        if ($shouldDownloadDump) {
            $importFilePath = $this->getRemoteDump();
        } elseif ($optionFile) {
            $localFilePath = $optionFile;
        } elseif ($optionDump) {
            $importFilePath = $this->getPathForDump($optionDump);
        } elseif ($optionLatest) {
            $importFilePath = $this->protector->getLatestDumpName();
            $this->info(sprintf('Importing <comment>%s</comment>', $importFilePath));
        } else {
            $importFilePath = $this->chooseImportDump($optionConnection);
        }

        if (empty($localFilePath)) {
            if (!$importFilePath) {
                $this->error('Found no file to import.');

                return self::FAILURE;
            }

            $localFilePath = $this->protector->createTempFilePath($importFilePath);
        }

        $this->importDump($localFilePath, $optionForce);

        if (!$optionFile) {
            unlink($localFilePath);
        }

        return self::SUCCESS;
    }

    /**
     * Reads the remote dump file and deletes all old dumps if the flush option is set.
     */
    protected function getRemoteDump(): ?string
    {
        $disk = $this->protector->getDisk();
        $basePath = $this->protector->getBaseDirectory();
        $serverUrl = $this->protector->getServerUrl();
        $absolutePath = $disk->path($basePath);

        $this->line(
            sprintf('<<< Downloading dump from remote server to directory: <comment>%s</comment>', $absolutePath)
        );

        try {
            $importFilePath = $this->protector->getRemoteDump();
        } catch (Exception $exception) {
            $this->error(sprintf('Error retrieving dump from remote server: %s', $exception->getMessage()));

            return null;
        }

        if ($this->option('flush')) {
            $this->protector->flush($importFilePath);
            $this->warn(sprintf('Deleted all old files in %s', $absolutePath));
        }

        $this->line(sprintf('>>> Successfully retrieved remote dump from %s', $serverUrl));

        return $importFilePath;
    }

    /**
     * Checks if a dump with the specified name exists and return the file path.
     *
     * @throws FileNotFoundException
     * @throws InvalidConfigurationException
     */
    protected function getPathForDump(string $dumpName): string
    {
        $filePath = implode(DIRECTORY_SEPARATOR, [$this->protector->getBaseDirectory(), $dumpName]);
        $disk = $this->protector->getDisk();

        if ($disk->exists($filePath)) {
            return $filePath;
        } else {
            throw new FileNotFoundException($filePath);
        }
    }

    /**
     * Returns the file path to a selected dump.
     */
    protected function chooseImportDump(?string $connectionName): string
    {
        $connectionFiles = $this->getConnectionFiles($connectionName);

        if ($connectionFiles->count() === 1) {
            $importFilePath = $connectionFiles->first()['path'];
            $this->info(sprintf('Using file "%s" because there are no other dumps.', $importFilePath));
        } else {
            $importFile = $this->choice(
                'Which file do you want to import?',
                $connectionFiles->map(function ($item) {
                    return $item['file'];
                })->toArray()
            );

            $importFilePath = $connectionFiles->firstWhere('file', $importFile)['path'];
        }

        return $importFilePath;
    }

    /**
     * Reads the metadata and returns a list of the available dumps.
     */
    public function getMetaDataForFiles(array $directoryFiles): Collection
    {
        $matchingFiles = collect();

        foreach ($directoryFiles as $directoryFile) {
            $metaData = $this->protector->getDumpMetaData($directoryFile);

            if ($this->option('ignore-connection-filter') || (!is_array($metaData) || empty($metaData))) {
                $matchingFiles->push([
                    'path' => $directoryFile,
                    'file' => basename($directoryFile),
                    'database' => '',
                    'connection' => 'external_dump',
                    'date' => '',
                    'time' => '',
                    'gitRevision' => '',
                    'gitBranch' => '',
                    'dateTime' => '',
                ]);

                continue;
            }

            if (($metaData['meta']['connection'] ?? false) && Arr::exists(
                config('database.connections'),
                $metaData['meta']['connection']
            )) {
                $fileInformation = [
                    'path' => $directoryFile,
                    'file' => basename($directoryFile),
                    'database' => Arr::get($metaData, 'meta.database', null),
                    'connection' => Arr::get($metaData, 'meta.connection', null),
                    'date' => Arr::get(
                        $metaData,
                        'meta.date',
                        sprintf(
                            '%4d-%2d-%2d',
                            Arr::get($metaData, 'meta.dumpedAtDate.year', '0000'),
                            Arr::get($metaData, 'meta.dumpedAtDate.mon', '00'),
                            Arr::get($metaData, 'meta.dumpedAtDate.mday', '00')
                        )
                    ),
                    'time' => Arr::get(
                        $metaData,
                        'meta.time',
                        sprintf(
                            '%2d-%2d-%2d',
                            Arr::get($metaData, 'meta.dumpedAtDate.hours', '00'),
                            Arr::get($metaData, 'meta.dumpedAtDate.minutes', '00'),
                            Arr::get($metaData, 'meta.dumpedAtDate.seconds', '00')
                        )
                    ),
                    'gitRevision' => Arr::get($metaData, 'meta.gitRevision', null),
                    'gitBranch' => Arr::get($metaData, 'meta.gitBranch', null),
                ];
            }

            $fileInformation['dateTime'] = trim(
                sprintf('%s %s', $fileInformation['date'], $fileInformation['time'])
            );

            $matchingFiles->push($fileInformation);
        }

        return $matchingFiles;
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function importDump(string $importFilePath, ?bool $optionForce): void
    {
        if ($optionForce || $this->confirm(
            sprintf(
                'Are you sure that you want to import the dump into the database: %s?',
                $this->protector->getDatabaseName()
            )
        )) {
            try {
                $this->protector->importDump($importFilePath, Arr::except($this->options(), ['migrate']));

                if ($this->option('migrate')) {
                    $this->call('migrate');
                }

                $this->info('Import done!');
            } catch (Exception $exception) {
                $this->error($exception->getMessage());
            }
        } else {
            $this->info('Import aborted');
        }
    }

    /**
     * Returns a list of either all dumps, or those for the specified connection name.
     *
     * @throws InvalidConnectionException|EmptyBaseDirectoryException
     */
    public function getConnectionFiles(?string $connectionName = null): Collection
    {
        $sortedFiles = $this->getMetaDataForFiles($this->protector->getDumpFiles())->sortByDesc('dateTime');

        if ($sortedFiles->isEmpty()) {
            throw new EmptyBaseDirectoryException();
        }

        if ($this->option('ignore-connection-filter')) {
            return $sortedFiles;
        }

        $filesByConnection = $sortedFiles->groupBy('connection');

        if ($connectionName && !$filesByConnection->has($connectionName)) {
            throw new InvalidConnectionException();
        }

        if (!$connectionName) {
            $connectionName = $this->chooseConnectionName($filesByConnection->keys());
        }

        return $filesByConnection->get($connectionName);
    }

    /**
     * Asks if an existing dump or a remote dump should be imported.
     */
    protected function userWantsRemoteDump(): bool
    {
        return match ($this->choice(
            'Do you want to download and import a fresh dump from the server or an existing local dump?',
            [
                1 => static::DOWNLOAD_REMOTE_DUMP,
                2 => static::IMPORT_EXISTING_LOCAL_DUMP,
            ],
            1
        )) {
            static::DOWNLOAD_REMOTE_DUMP => true,
            default => false,
        };
    }

    /**
     * Returns the connection name for dump imports.
     * Asks the user if there are multiple possibilities.
     */
    protected function chooseConnectionName(Collection $connectionNames): string
    {
        if ($connectionNames->count() === 1) {
            $connectionName = $connectionNames->first();

            $this->info(
                sprintf(
                    'Using connection "%s" because there are no dumps created through other connections.',
                    $connectionName
                )
            );

            return $connectionName;
        }

        return $this->choice('Import dump for which connection?', $connectionNames->toArray());
    }
}
