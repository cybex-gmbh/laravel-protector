<?php

namespace Cybex\Protector\Commands;

use Carbon\Carbon;
use Cybex\Protector\Contracts\ProtectorConfigContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use League\Flysystem\Local\LocalFilesystemAdapter;

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
                {--f|file= : Either an absolute path to a file, or a filename relative to the protector dump directory. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--force : Skips confirmation prompts. Requires the file, remote or latest option. }
                {--i|ignore-connection-filter : Ignores filter of dumps to defined connections. }
                {--r|remote : Pull a fresh dump from the remote server as configured in the .env file. Will be used as fallback when combined with other options. }
                {--flush : Delete all existing dumps in the dump folder when using a remote dump. }
                {--l|latest : Import the most recent dump available in the configured dumps directory. }
                {--m|migrate : Run database migrations after import. }
                {--w|no-wipe : Do not wipe the database before importing the dump. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports a local or remote database dump.';

    protected const DOWNLOAD_REMOTE_DUMP = 'Download remote dump';
    protected const IMPORT_EXISTING_LOCAL_DUMP = 'Import existing local dump';
    protected Protector $protector;
    protected ProtectorConfigContract $protectorConfig;

    /**
     * Execute the console command.
     *
     * @return int
     * @throws InvalidEnvironmentException
     */
    public function handle(): int
    {
        $this->newLine();

        if (App::environment('production') && !$this->option('allow-production')) {
            throw new InvalidEnvironmentException(
                'Import is not allowed on production systems! Use --allow-production'
            );
        }

        $this->protector = app('protector');
        $this->protectorConfig = $this->protector->getConfig();

        $this->protector->guardRequiredFunctionsEnabled();
        $this->protectorConfig->setConnectionName($this->option('connection'));

        $hasFile = !empty(trim($this->option('file')));

        if ($this->option('force') && !($this->option('remote') || $hasFile || $this->option('latest'))) {
            $this->error('Nothing to import. You need to specify either --remote, --file, or --latest.');

            return self::FAILURE;
        }

        $dumpFilePath = match (true) {
            $this->option('remote') => $this->getDumpFromRemote(),
            $hasFile => $this->getDumpFromFile(),
            $this->option('latest') => $this->getLatestDump(),
            default => $this->getDumpInteractive(),
        };

        $this->prepareAndImportDumpFile($dumpFilePath);

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Reads the remote dump file and deletes all old dumps if the flush option is set.
     */
    protected function getDumpFromRemote(): string
    {
        $disk = $this->protectorConfig->getDisk();
        $basePath = $this->protectorConfig->getBaseDirectory();
        $dumpEndpointUrl = $this->protectorConfig->getDumpEndpointUrl();
        $absoluteBasePath = $disk->path($basePath);

        $this->line(
            sprintf('<<< Downloading dump from remote server to directory: <comment>%s</comment>', $absoluteBasePath)
        );

        $relativeRemoteDumpFilePath = $this->protector->getRemoteDump();

        $this->line(sprintf('>>> Successfully retrieved remote dump from %s', $dumpEndpointUrl));

        if ($this->option('flush')) {
            $this->protector->flush(excludeFile: $relativeRemoteDumpFilePath);
            $this->warn(sprintf('Deleted all old files in %s', $absoluteBasePath));
        }

        return $relativeRemoteDumpFilePath;
    }

    /**
     * Imports a dump from a specific file path.
     * The file path may be either absolute or relative to the dump directory.
     *
     * @throws FileNotFoundException
     */
    protected function getDumpFromFile(): string
    {
        $isAbsoluteFilePath = $this->isAbsolutePath($this->option('file'));

        switch ($isAbsoluteFilePath) {
            case true:
                $dumpFilePath = $this->option('file');
                $fileExists = file_exists($dumpFilePath);
                break;
            default:
                $dumpFilePath = implode(DIRECTORY_SEPARATOR, [$this->protectorConfig->getBaseDirectory(), $this->option('file')]);
                $fileExists = $this->protectorConfig->getDisk()->exists($dumpFilePath);
                break;
        }

        if (!$fileExists) {
            throw new FileNotFoundException($dumpFilePath);
        }

        // Might be absolute or relative at this point.
        return $dumpFilePath;
    }

    protected function getLatestDump(): string
    {
        $relativeImportFilePath = $this->protector->getLatestDumpName();

        $this->info(sprintf('Importing <comment>%s</comment>', $relativeImportFilePath));

        return $relativeImportFilePath;
    }

    protected function getDumpInteractive(): string
    {
        if ($this->userWantsRemoteDump()) {
            return $this->getDumpFromRemote();
        }

        return $this->chooseImportDump($this->option('connection'));
    }

    /**
     * Imports a dump file.
     * If it is stored remotely, a local temporary file is created, imported, and deleted afterward.
     */
    protected function prepareAndImportDumpFile(string $dumpFilePath): void
    {
        // We need an absolute and local file path going forward. The file path may already be absolute and local, only if called with the --file option and passed an absolute path.
        if (!$this->isAbsolutePath($dumpFilePath)) {
            // The Protector disk might be local, if not, we need to create a local temp file.
            if (is_a($this->protectorConfig->getDisk()->getAdapter(), LocalFilesystemAdapter::class)) {
                $dumpFilePath = $this->protectorConfig->getDisk()->path($dumpFilePath);
            } else {
                $absoluteTempFilePath = $this->protector->createTempFilePath($dumpFilePath);
            }
        }

        try {
            $this->importDump($absoluteTempFilePath ?? $dumpFilePath, $this->option('force'));
        } finally {
            !empty($absoluteTempFilePath) && unlink($absoluteTempFilePath);
        }
    }

    /**
     * Returns the file path to a selected dump.
     */
    protected function chooseImportDump(?string $connectionName): string
    {
        $connectionFiles = $this->getConnectionFiles($connectionName);

        if ($connectionFiles->count() === 1) {
            $relativeImportFilePath = $connectionFiles->first()['path'];

            $this->info(sprintf('Using file "%s" because there are no other dumps.', $relativeImportFilePath));
        } else {
            $importFile = $this->choice(
                'Which file do you want to import?',
                $connectionFiles->map(function ($item) {
                    return $item['file'];
                })->toArray()
            );

            $relativeImportFilePath = $connectionFiles->firstWhere('file', $importFile)['path'];
        }

        return $relativeImportFilePath;
    }

    /**
     * Reads the metadata and returns a list of the available dumps.
     */
    protected function getMetadataForFiles(array $directoryFiles): Collection
    {
        $matchingFiles = collect();

        foreach ($directoryFiles as $directoryFile) {
            $metadata = $this->protector->getDumpMetadata($directoryFile);

            if ($this->option('ignore-connection-filter') || (!is_array($metadata) || empty($metadata))) {
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

            // Legacy format is a flat array.
            $isLegacyDump = !is_array($metadata['meta']['database']);

            $database = Arr::get($metadata, $isLegacyDump ? 'meta.database' : 'meta.database.database');
            $connection = Arr::get($metadata, $isLegacyDump ? 'meta.connection' : 'meta.database.connection');
            $dumpedAtDateString = Arr::get($metadata, $isLegacyDump ? 'meta.dumpedAtDate' : 'meta.database.dumpedAtDate');

            if (Arr::exists(config('database.connections'), $connection)) {
                $dumpedAtDate = $dumpedAtDateString ? Carbon::parse($dumpedAtDateString) : null;

                $fileInformation = [
                    'path' => $directoryFile,
                    'file' => basename($directoryFile),
                    'database' => $database,
                    'connection' => $connection,
                    'date' => $dumpedAtDate?->format('Y-m-d'),
                    'time' => $dumpedAtDate?->format('H:i:s'),
                    'dateTime' => $dumpedAtDate?->format('Y-m-d H:i:s'),
                ];

                $matchingFiles->push($fileInformation);
            } else {
                $this->warn(sprintf('Skipping file "%s" because the connection "%s" is not valid.', $directoryFile, $connection));
            }
        }

        return $matchingFiles;
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function importDump(string $absoluteImportFilePath, ?bool $optionForce): void
    {
        if ($optionForce || $this->confirm(
                sprintf(
                    'Are you sure that you want to import the dump into the database: %s?',
                    $this->protectorConfig->getDatabaseName()
                )
            )) {

            $this->protector->importDump($absoluteImportFilePath, options: Arr::except($this->options(), ['migrate']));

            if ($this->option('migrate')) {
                $this->call('migrate');
            }

            $this->info('Import done!');
        } else {
            $this->info('Import aborted');
        }
    }

    /**
     * Returns a list of either all dumps or those for the specified connection name.
     *
     * @throws InvalidConnectionException|EmptyBaseDirectoryException
     */
    protected function getConnectionFiles(?string $connectionName = null): Collection
    {
        $sortedFiles = $this->getMetadataForFiles($this->protector->getDumpFiles())->sortByDesc('dateTime');

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
     *
     * @return bool
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

    protected function isAbsolutePath(string $filePath): bool
    {
        return str_starts_with($filePath, DIRECTORY_SEPARATOR);
    }
}
