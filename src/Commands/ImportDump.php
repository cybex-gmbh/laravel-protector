<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConfigurationException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * Class ImportDump
 *
 * @package Cybex\Protector\Commands
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

    protected const DOWNLOAD_REMOTE_DUMP       = 'Download remote dump';
    protected const IMPORT_EXISTING_LOCAL_DUMP = 'Import existing local dump';
    protected ?Protector $protector            = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws InvalidEnvironmentException
     */
    public function handle(): void
    {
        $optionDump       = $this->option('dump');
        $optionFile       = $this->option('file');
        $optionRemote     = $this->option('remote');
        $optionForce      = $this->option('force');
        $optionLatest     = $this->option('latest');
        $optionConnection = $this->option('connection');

        if (App::environment('production') && !$this->option('allow-production')) {
            throw new InvalidEnvironmentException('Import is not allowed on production systems! Use --allow-production');
        }

        if ($optionForce && !($optionRemote || $optionFile || $optionDump)) {
            $this->error('The force option requires either the file, dump or remote option to be set.');

            return;
        }

        $this->protector = app('protector');

        $this->setConnection($optionConnection);
        $optionRemote   = $optionRemote || $this->shouldDownloadDump();

        if ($optionRemote) {
            $importFilePath = $this->getRemoteDump();
        } elseif ($optionFile) {
            $localFilePath  = $optionFile;
        } elseif ($optionDump) {
            $importFilePath = $this->getPathForDump($optionDump);
        } elseif ($optionLatest) {
            $importFilePath = $this->protector->getLatestDumpName();
            $this->info(sprintf('Using %s.', $importFilePath));
        } else {
            $importFilePath = $this->chooseImportDump($optionConnection);
        }

        if (empty($localFilePath)) {
            $localFilePath = $this->protector->createTempFilePath($importFilePath);
        }

        $this->importDump($localFilePath, $optionForce);

        if (!$optionFile) {
            unlink($localFilePath);
        }
    }

    /**
     * Returns the valid connection
     *
     * @param string|null $connectionName
     * @return void
     * @throws InvalidConfigurationException
     */
    public function setConnection(?string $connectionName): void
    {
        if (!$this->protector->configure($connectionName)) {
            throw new InvalidConfigurationException('Configuration is invalid');
        }
    }

    /**
     * Public function to ask if an existing dump or a remote dump should be imported.
     *
     * @return bool
     */
    public function shouldDownloadDump(): bool
    {
        if (!($this->option('file') || $this->option('dump') || $this->option('latest'))) {
            if ($this->choice(
                    'Do you want to download and import a fresh dump from the server or an existing local dump?',
                    [
                        1 => static::DOWNLOAD_REMOTE_DUMP,
                        2 => static::IMPORT_EXISTING_LOCAL_DUMP,
                    ],
                    1
                ) === static::DOWNLOAD_REMOTE_DUMP) {
                $optionRemote = true;
            }
        }

        return $optionRemote ?? false;
    }

    /**
     * Reads the remote dump file and deletes all old dumps if the flush option is set.
     *
     * @return string|void
     */
    protected function getRemoteDump()
    {
        $disk = $this->protector->getDisk();
        $basePath = $this->protector->getBaseDirectory();

        $this->line(sprintf('<<< Downloading dump from remote server to directory: <comment>%s</comment>', $disk->path($basePath)));

        try {
            $importFilePath = $this->protector->getRemoteDump();
        } catch (Exception $exception) {
            $this->error(sprintf('Error retrieving dump from remote server: %s', $exception->getMessage()));

            return;
        }

        if (!$disk->size($importFilePath)) {
            $this->error(sprintf('Retrieved empty response from %s', $this->protector->getServerUrl()));
            $disk->delete($importFilePath);

            return;
        }

        if ($this->option('flush')) {
            $this->protector->flush($importFilePath);
            $this->warn(sprintf('Deleted all old files in %s', $disk->path($basePath)));
        }

        $this->line(sprintf('>>> Successfully retrieved remote dump from %s', $this->protector->getServerUrl()));

        return $importFilePath;
    }

    /**
     * Checks if a dump with the specified name exists and return the file path.
     *
     * @param string $dumpName
     * @return string
     * @throws FileNotFoundException
     */
    protected function getPathForDump(string $dumpName): string
    {
        $filePath = implode(DIRECTORY_SEPARATOR, array_filter([$this->protector->getBaseDirectory(), $dumpName]));
        $disk     = $this->protector->getDisk();

        if ($disk->exists($filePath)) {
            return $filePath;
        } else {
            throw new FileNotFoundException($filePath);
        }
    }

    /**
     * Returns the file path to a selected dump.
     *
     * @param string|null $connectionName
     * @return string
     */
    protected function chooseImportDump(?string $connectionName): string
    {
        $disk            = $this->protector->getDisk();
        $basePath        = $this->protector->getBaseDirectory();
        $directoryFiles  = $disk->files($basePath);
        $matchingFiles   = $this->getDumpMetadata($directoryFiles);
        $connectionFiles = $this->getConnectionFiles($matchingFiles, $connectionName);

        switch ($connectionFiles->count()) {
            case 0:
                throw new LogicException('There are no dumps in the dump folder');
            case 1:
                $importFilePath = $connectionFiles->first()['path'];
                $this->info(sprintf('Using file "%s" because there are no other dumps.', $importFilePath));
                break;
            default:
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
     *
     * @param array $directoryFiles
     * @return Collection
     */
    public function getDumpMetadata(array $directoryFiles): Collection
    {
        $matchingFiles = collect();

        foreach ($directoryFiles as $directoryFile) {
            $metaData = $this->protector->getDumpMetaData($directoryFile);

            if (is_array($metaData) && !empty($metaData) && array_key_exists($metaData['meta']['connection'], config('database.connections'))) {

                $fileInformation = [
                    'path'        => $directoryFile,
                    'file'        => $directoryFile,
                    'database'    => Arr::get($metaData, 'meta.database', null),
                    'connection'  => Arr::get($metaData, 'meta.connection', null),
                    'date'        => Arr::get(
                        $metaData,
                        'meta.date',
                        sprintf(
                            '%4d-%2d-%2d',
                            Arr::get($metaData, 'meta.dumpedAtDate.year', '0000'),
                            Arr::get($metaData, 'meta.dumpedAtDate.mon', '00'),
                            Arr::get($metaData, 'meta.dumpedAtDate.mday', '00')
                        )
                    ),
                    'time'        => Arr::get(
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
                    'gitBranch'   => Arr::get($metaData, 'meta.gitBranch', null),
                ];

                $fileInformation['dateTime'] = trim(
                    sprintf('%s %s', $fileInformation['date'], $fileInformation['time'])
                );

                $matchingFiles->push($fileInformation);
            } elseif ($this->option('ignore-connection-filter') || (!is_array($metaData) || empty($metaData))) {
                $matchingFiles->push([
                    'path'        => $directoryFile,
                    'file'        => $directoryFile,
                    'database'    => '',
                    'connection'  => 'external_dump',
                    'date'        => '',
                    'time'        => '',
                    'gitRevision' => '',
                    'gitBranch'   => '',
                    'dateTime'    => '',
                ]);
            }
        }

        return $matchingFiles;
    }

    /**
     * Imports the selected SQL dump.
     *
     * @param string $importFilePath
     * @param bool|null $optionForce
     * @return void
     */
    public function importDump(string $importFilePath, ?bool $optionForce): void
    {
        if ($importFilePath && ($optionForce || $this->confirm(sprintf('Are you sure that you want to import the dump into the database: %s?', $this->protector->getDatabaseName())))) {
            try {
                $this->protector->importDump($importFilePath, $this->options());
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
     * @param Collection $matchingFiles
     * @param string|null $connectionName
     * @return Collection
     */
    public function getConnectionFiles(Collection $matchingFiles, ?string $connectionName = null): Collection
    {
        $sortedFiles = $matchingFiles->sortByDesc('dateTime')->groupBy('connection');

        if ($sortedFiles->count() == 1) {
            $connectionName = Arr::first($sortedFiles->keys()->sort()->toArray());
            $this->info(
                sprintf(
                    'Using connection "%s" because there are no dumps created through other connections.',
                    $connectionName
                )
            );
        } elseif (!$this->option('ignore-connection-filter')) {
            // In this case don't limit the files to the connection, no code required.
        } elseif ($connectionName) {
            $connectionName = $this->choice('Import dump for which connection?', $sortedFiles->keys()->toArray());
            $this->info(sprintf('Using connection "%s".', $connectionName));
        }

        if ($connectionName) {
            $connectionFiles = $sortedFiles->get($connectionName);
        } else {
            $connectionFiles = $matchingFiles->sortByDesc('dateTime');
        }

        return $connectionFiles;
    }
}
