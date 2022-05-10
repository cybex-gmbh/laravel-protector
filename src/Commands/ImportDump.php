<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Protector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
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
    protected ?Protector $protector = null;

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
     */
    public function handle()
    {
        $optionDump    = $this->option('dump');
        $optionFile    = $this->option('file');
        $optionRemote  = $this->option('remote');
        $optionForce   = $this->option('force');
        $optionLatest  = $this->option('latest');
        $optionMigrate = $this->option('migrate');

        if ($optionForce && !($optionRemote || $optionFile || $optionDump)) {
            $this->error('The force option requires either the file, dump or remote option to be set.');

            return;
        }

        $this->protector = app('protector');

        $disk                = $this->protector->getDisk();
        $basePath            = config('protector.baseDirectory');
        $destinationFilename = $optionDump ?: $this->protector->createFilename();
        $filePath            = $optionFile ?: $this->protector->getDumpFilePath($destinationFilename);
        $importFilePath      = null;

        $connectionName = null;

        if ($this->option('connection')) {
            $connectionName = $this->option('connection');
        }

        if (App::environment('production') && !$this->option('allow-production')) {
            $this->error('Import is not allowed on production systems! Use --allow-production');

            return;
        }

        if (!$this->protector->configure($connectionName)) {
            $this->error('Configuration is invalid');

            return;
        }

        if (!($optionRemote || $optionFile || $optionDump || $optionLatest)) {
            if ($this->choice('Do you want to download and import a fresh dump from the server or an existing local dump?',
                    [
                        1 => static::DOWNLOAD_REMOTE_DUMP,
                        2 => static::IMPORT_EXISTING_LOCAL_DUMP,
                    ],
                    1) === static::DOWNLOAD_REMOTE_DUMP) {
                $optionRemote = true;
            }
        }

        if ($optionLatest) {
            try {
                $filePath = $this->protector->getLatestDumpName();
                $importFilePath = $this->protector->createTempFilePath($filePath);
            } catch (FileNotFoundException $fileNotFoundException) {
                if (!$optionRemote) {
                    $this->error($fileNotFoundException->getMessage());

                    return;
                } else {
                    $this->warn(sprintf('There are no files in %s', $disk->path($basePath)));
                }
            }
        } elseif ($optionFile || $optionDump) {
            if ($optionFile) {
                $importFilePath = $filePath;
            } elseif ($disk->exists($filePath)) {
                $importFilePath = $this->protector->createTempFilePath($filePath);
            } elseif (!$optionRemote) {
                $this->error((new FileNotFoundException($filePath))->getMessage());

                return;
            } else {
                $this->warn(sprintf('File not found: %s', $filePath));
            }
        }

        if (!$importFilePath && $optionRemote) {
            $this->line(sprintf('<<< Downloading dump from remote server to directory: <comment>%s</comment>', $disk->path($basePath)));

            try {
                $filePath = $this->protector->getRemoteDump();
                $importFilePath = $this->protector->createTempFilePath($filePath);
            } catch (Exception $exception) {
                $this->error(sprintf('Error retrieving dump from remote server: %s', $exception->getMessage()));

                return;
            }

            if (!$disk->size($filePath)) {
                $this->error(sprintf('Retrieved empty response from %s', $this->protector->getServerUrl()));
                $disk->delete($filePath);

                return;
            }

            if ($this->option('flush')) {
                $this->protector->flush($filePath);
                $this->warn(sprintf('Deleted all old files in %s', $disk->path($basePath)));
            }

            $this->line(sprintf('>>> Successfully retrieved remote dump from %s', $this->protector->getServerUrl()));
        }

        if (!$importFilePath) {
            $directoryFiles = $disk->files($basePath);
            $matchingFiles  = collect();

            foreach ($directoryFiles as $directoryFile) {
                $metaData = $this->protector->getDumpMetaData($directoryFile);

                if (is_array($metaData) && !empty($metaData) && array_key_exists($metaData['meta']['connection'], config('database.connections'))) {

                    $fileInformation = [
                        'path'        => $directoryFile,
                        'file'        => $directoryFile,
                        'database'    => Arr::get($metaData, 'meta.database', null),
                        'connection'  => Arr::get($metaData, 'meta.connection', null),
                        'date'        => Arr::get($metaData,
                            'meta.date',
                            sprintf('%4d-%2d-%2d',
                                Arr::get($metaData, 'meta.dumpedAtDate.year', '0000'),
                                Arr::get($metaData, 'meta.dumpedAtDate.mon', '00'),
                                Arr::get($metaData, 'meta.dumpedAtDate.mday', '00'))),
                        'time'        => Arr::get($metaData,
                            'meta.time',
                            sprintf('%2d-%2d-%2d',
                                Arr::get($metaData, 'meta.dumpedAtDate.hours', '00'),
                                Arr::get($metaData, 'meta.dumpedAtDate.minutes', '00'),
                                Arr::get($metaData, 'meta.dumpedAtDate.seconds', '00'))),
                        'gitRevision' => Arr::get($metaData, 'meta.gitRevision', null),
                        'gitBranch'   => Arr::get($metaData, 'meta.gitBranch', null),
                    ];

                    $fileInformation['dateTime'] = trim(sprintf('%s %s', $fileInformation['date'], $fileInformation['time']));

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

            $sortedFiles = $matchingFiles->sortByDesc('dateTime')->groupBy('connection');

            if ($sortedFiles->count() == 1) {
                $connectionName = Arr::first($sortedFiles->keys()->sort()->toArray());
                $this->info(sprintf('Using connection "%s" because there are no dumps created through other connections.', $connectionName));
            } elseif ($this->option('ignore-connection-filter')) {
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

            if ($connectionFiles->count() === 1) {
                $filePath = $connectionFiles->first()['path'];
                $importFilePath   = $this->protector->createTempFilePath($filePath);
                $this->info(sprintf('Using file "%s" because there are no other dumps.', $filePath));
            } else {
                try {
                    $importFile = $this->choice('Which file do you want to import?',
                        $connectionFiles->map(function ($item) {
                            return $item['file'];
                        })->toArray());
                } catch (LogicException) {
                    $this->error('There are no dumps in the dump folder.');

                    return;
                }

                $filePath = $connectionFiles->firstWhere('file', $importFile)['path'];
                $importFilePath = $this->protector->createTempFilePath($filePath);
            }
        }

        if ($importFilePath && ($optionForce || $this->confirm(sprintf('Are you sure that you want to import the dump into the database: %s?', $this->protector->getDatabaseName())))) {
            $this->info(sprintf('Importing %s. Running migrations: %s', $filePath, $optionMigrate ? 'yes' : 'no'));

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
}
