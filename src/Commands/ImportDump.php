<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
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
                {--c|connection= : The configured database-connection in Laravels config/database.php. }
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--force : Forces the import of the given file or remote download. Requires the dump, file or remote option. }
                {--i|ignore-connection-filter : Ignores filter of dumps to defined connections. }
                {--r|remote : Pull a fresh dump from the remote server as configured in the .env file. }
                {--flush : Delete all existing dumps in the dump folder when using a remote dump}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports a dump.';

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
        $optionDump   = $this->option('dump');
        $optionFile   = $this->option('file');
        $optionRemote = $this->option('remote');
        $optionForce  = $this->option('force');

        if ($optionForce && !($optionRemote || $optionFile || $optionDump)) {
            $this->error('The force option requires either the file, dump or remote option to be set.');
            return;
        }

        $protector = app('protector');

        $disk                    = $protector->getDisk();
        $basePath                = config('protector.baseDirectory');
        $destinationFilename     = $optionDump ?: $protector->createFilename();
        $relativePath            = $optionFile ?: $basePath . DIRECTORY_SEPARATOR . $destinationFilename;

        $connectionName = null;

        if ($this->option('connection')) {
            $connectionName = $this->option('connection');
        }

        $options                     = [];
        $options['allow-production'] = $this->option('allow-production') ?: false;

        if (App::environment('production') && !$this->option('allow-production')) {
            $this->error('Import is not allowed on production systems! Use --allow-production');
            return;
        }

        if (!$protector->configure($connectionName)) {
            $this->error('Configuration is invalid');
            return;
        }

        if (!($optionRemote || $optionFile || $optionDump)) {
            if ($this->choice('Do you want to download and import a fresh dump from the server or an existing local dump?',
                    [
                        '1' => 'Download remote dump',
                        '2' => 'Import existing local dump',
                    ],
                    'Download remote dump') == 'Download remote dump') {
                $optionRemote = true;
            };
        }

        if ($optionRemote) {
            if ($this->option('flush')) {
                $disk->delete($disk->files($basePath));
                $this->warn(sprintf('Deleted all files in %s'), $disk->path($basePath));
            }

            $this->line(sprintf('<<< Downloading dump from remote server to directory: <comment>%s</comment>', $disk->path($basePath)));

            try {
                $fullRemoteDumpFileName = $protector->getRemoteDump();
            } catch (Exception $exception) {
                $this->error(sprintf('Error retrieving dump from remote server: %s', $exception->getMessage()));
                return;
            }

            $this->line(sprintf('>>> Successfully retrieved remote dump from %s', app('protector')->getServerUrl()));

            $importFilePath = $fullRemoteDumpFileName;
        } elseif ($optionFile || $optionDump) {
            $importFilePath = $relativePath;
        } else {
            $directoryFiles = $disk->files($basePath);
            $matchingFiles  = collect();

            foreach ($directoryFiles as $directoryFile) {
                $metaData = $protector->getDumpMetaData($directoryFile);

                if (is_array($metaData) && !empty($metaData) && array_key_exists($metaData['meta']['connection'], config('database.connections'))) {

                    $fileInformation = [
                        'path'        => $basePath . DIRECTORY_SEPARATOR . $directoryFile,
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
                        'path'        => $basePath . DIRECTORY_SEPARATOR . $directoryFile,
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
                // In this case dont limit the files to the connection, no code required.
            } elseif ($connectionName) {
                $connectionName = $this->choice('Import dump for which connection?', $sortedFiles->keys()->toArray());
                $this->info(sprintf('Using connection "%s".', $connectionName));
            }

            if ($connectionName) {
                $connectionFiles = $sortedFiles->get($connectionName);
            } else {
                $connectionFiles = $matchingFiles->sortByDesc('dateTime');
            }

            if ($connectionFiles->count() == 1) {
                $importFilePath = $connectionFiles->first()['path'];
                $this->info(sprintf('Using file "%s" because there are no other dumps.', $importFilePath));
            } else {
                try {
                    $importFile = $this->choice('Which file do you want to import?',
                        $connectionFiles->map(function ($item) {
                            return $item['file'];
                        })->toArray());
                } catch (LogicException $logicException) {
                    $this->error('There are no dumps in the dump folder.');
                    return;
                }

                $importFilePath = $connectionFiles->firstWhere('file', $importFile)['path'];
            }
        }

        if ($importFilePath && ($optionForce || $this->confirm(sprintf('Are you sure that you want to import the dump at %s?', $disk->path($importFilePath))))) {
            // Import the desired dump.
            $protector->importDump($importFilePath, $options);

        } else {
            $this->info('Import aborted');
        }
    }
}
