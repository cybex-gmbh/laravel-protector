<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

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

        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->createProtector();
        $this->protector->guardRequiredFunctionsEnabled();

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
        $this->line(
            sprintf('<<< Downloading dump from remote server to disk <comment>%s</comment>, path <comment>%s</comment>', $this->protector->getDiskName(), $this->protector->getDiskBaseDirectory())
        );

        $relativeRemoteDumpFilePath = $this->protector->getRemoteDump();

        $this->line('>>> Successfully retrieved remote dump.');

        if ($this->option('flush')) {
            $this->protector->flush(excludeFile: $relativeRemoteDumpFilePath);
            $this->warn(sprintf('Deleted all old files on disk %s in path %s', $this->protector->getDiskName(), $this->protector->getDiskBaseDirectory()));
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

                if (file_exists($dumpFilePath)) {
                    throw new FileNotFoundException($dumpFilePath);
                }

                break;
            default:
                // This will throw an exception if the dump file was not found.
                $dumpFilePath = $this->protector->getDumpFile($this->option('file'));
                break;
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
            $absoluteTempFilePath = $this->protector->createTempFilePath($dumpFilePath);
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
        $connectionFiles = $this->getConnectionFiles($connectionName)->keys();

        if ($connectionFiles->count() === 1) {
            $relativeImportFilePath = $connectionFiles->first();

            $this->info(sprintf('Using file "%s" because there are no other dumps.', $relativeImportFilePath));
        } else {
            $importFile = $this->choice(
                'Which file do you want to import?',
                $connectionFiles->toArray()
            );

            $relativeImportFilePath = $connectionFiles->firstWhere(fn($file) => $file === $importFile);
        }

        return $relativeImportFilePath;
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function importDump(string $absoluteImportFilePath, ?bool $optionForce): void
    {
        if ($optionForce || $this->confirm(
                sprintf(
                    'Are you sure that you want to import the dump into the database: %s?',
                    $this->protector->getDatabaseName()
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
        $sortedFiles = $this->protector->getDumpFilesWithMetadata()
            ->sortByDesc(
            // Supporting the legacy format.
                fn($file) => Arr::get($file, 'meta.database.dumpedAtDate') ?? Arr::get($file, 'meta.dumpedAtDate')
            )->filter(function ($fileInfo, $fileName) {
                // Filter connections which are not defined in the database config file.
                $connection = Arr::get($fileInfo, 'meta.database.connection') ?? Arr::get($fileInfo, 'meta.connection') ?: 'external_dump';

                if ($connection === 'external_dump' || Arr::exists(config('database.connections'), $connection)) {
                    return true;
                }

                $this->warn(sprintf('Skipping file "%s" because the connection "%s" is not valid.', $fileName, $connection));

                return false;
            });

        if ($sortedFiles->isEmpty()) {
            throw new EmptyBaseDirectoryException();
        }

        if ($this->option('ignore-connection-filter')) {
            return $sortedFiles;
        }

        $filesByConnection = $sortedFiles->groupBy(
        // Supporting the legacy format.
            fn($file) => Arr::get($file, 'meta.database.connection') ?? Arr::get($file, 'meta.connection') ?: 'external_dump',
            preserveKeys: true
        );

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
