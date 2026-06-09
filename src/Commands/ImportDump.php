<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Class ImportDump
 *
 * @package Cybex\Protector\Commands
 */
class ImportDump extends Command
{
    protected const string UNKNOWN_CONNECTION_NAME = 'unknown_connection';

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
                {--r|remote : Pull a fresh dump from the remote server as configured in the .env file. Will be used as fallback when combined with other options. }
                {--l|latest : Import the most recent dump available in the configured dumps directory. }
                {--m|migrate : Run database migrations after import. }
                {--w|no-wipe : Do not wipe the database before importing the dump. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports a local or remote database dump.';

    protected const string DOWNLOAD_REMOTE_DUMP = 'Download remote dump';
    protected const string IMPORT_EXISTING_LOCAL_DUMP = 'Import existing dump';
    protected Protector $protector;
    protected Filesystem $sourceDisk;
    protected bool $needsCleanup = false;

    /**
     * Execute the console command.
     *
     * @return int
     *
     * @throws EmptyBaseDirectoryException
     * @throws FileNotFoundException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     * @throws ShellAccessDeniedException
     */
    public function handle(): int
    {
        if (App::environment('production') && !$this->option('allow-production')) {
            throw new InvalidEnvironmentException(
                'Import is not allowed on production systems! Use --allow-production'
            );
        }

        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->makeProtector();
        $this->protector->guardRequiredFunctionsEnabled();
        $this->sourceDisk = DiskHelper::getStorageDisk();

        $hasFile = !empty(trim($this->option('file')));

        if ($this->option('force') && !($this->option('remote') || $hasFile || $this->option('latest'))) {
            error('Nothing to import. You need to specify either --remote, --file, or --latest.');

            return self::FAILURE;
        }

        $dumpSource = match (true) {
            $this->option('remote') => $this->getDumpFromRemote(),
            $hasFile => $this->getDumpFromFile(),
            $this->option('latest') => $this->getLatestDump(),
            default => $this->getDumpInteractive(),
        };

        $this->runImport($dumpSource);

        return self::SUCCESS;
    }

    protected function getDumpFromRemote(): string
    {
        $dumpPath = DiskHelper::localPath();
        $this->sourceDisk = DiskHelper::getLocalDisk();
        $this->needsCleanup = true;

        spin(
            callback: fn() => $this->protector->download(storageFilePath: $dumpPath, storageDisk: $this->sourceDisk),
            message: 'Downloading dump...'
        );

        info('Successfully retrieved remote dump.');

        return $dumpPath;
    }

    /**
     * Imports a dump from a specific file path.
     * The file path may be either absolute or relative to the dump directory.
     *
     * @throws FileNotFoundException
     */
    protected function getDumpFromFile(): string
    {
        if (DiskHelper::isAbsolutePath($this->option('file'))) {
            $absoluteDumpPath = $this->option('file');

            if (!file_exists($absoluteDumpPath)) {
                throw new FileNotFoundException($absoluteDumpPath);
            }

            return $absoluteDumpPath;
        }

        // This will throw an exception if the dump file was not found.
        return $this->protector->dumpFile($this->option('file'));
    }

    /**
     * @throws EmptyBaseDirectoryException
     */
    protected function getLatestDump(): string
    {
        $dumpPath = $this->protector->latestDumpName();

        info(sprintf('Importing %s', $dumpPath));

        return $dumpPath;
    }

    /**
     * @throws EmptyBaseDirectoryException
     * @throws InvalidConnectionException
     */
    protected function getDumpInteractive(): string
    {
        if ($this->userWantsRemoteDump()) {
            return $this->getDumpFromRemote();
        }

        return $this->chooseImportDump($this->option('connection'));
    }

    /**
     * Returns the file path to a selected dump.
     *
     * @throws EmptyBaseDirectoryException
     * @throws InvalidConnectionException
     */

    protected function chooseImportDump(?string $connectionName): string
    {
        $connectionFiles = $this->getConnectionFiles($connectionName)->keys();

        if ($connectionFiles->count() === 1) {
            $dumpPath = $connectionFiles->first();

            info(sprintf('Using file "%s" because there are no other dumps.', $dumpPath));

            return $dumpPath;
        }

        $selectedFile = select(
            label: 'Which file do you want to import?',
            options: $connectionFiles,
        );

        return $connectionFiles->firstWhere(fn($file) => $file === $selectedFile);
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function runImport(string $dumpPath): void
    {
        try {
            if ($this->option('force') || confirm(
                    sprintf(
                        'Are you sure that you want to import the dump into the database: %s?',
                        $this->protector->getDatabaseName()
                    )
                )) {
                spin(
                    callback: fn() => $this->protector->import(
                        $dumpPath,
                        $this->sourceDisk,
                        noWipe: $this->option('no-wipe'),
                        migrate: $this->option('migrate'),
                        allowProduction: $this->option('allow-production'),
                    ),
                    message: 'Importing dump...'
                );

                info('Import done!');

                return;
            }

            info('Import aborted');
        } finally {
            // Clean-up local in case there was a dump downloaded from remote.
            if ($this->needsCleanup) {
                DiskHelper::deleteLocalFile($dumpPath);
            }
        }
    }

    /**
     * Returns a list of either all dumps or those for the specified connection name.
     *
     * @throws InvalidConnectionException|EmptyBaseDirectoryException
     */
    protected function getConnectionFiles(?string $connectionName = null): Collection
    {
        $sortedFiles = $this->protector->dumpFilesWithMetadata()
            ->sortByDesc(
            // Supporting the legacy format.
                fn($file) => Arr::get($file, 'meta.database.dumpedAtDate') ?? Arr::get($file, 'meta.dumpedAtDate')
            )->filter(function ($fileInfo, $fileName) {
                // Filter connections which are not defined in the database config file.
                $connection = Arr::get($fileInfo, 'meta.database.connection') ?? Arr::get($fileInfo, 'meta.connection') ?: static::UNKNOWN_CONNECTION_NAME;

                if ($connection === static::UNKNOWN_CONNECTION_NAME || Arr::exists(config('database.connections'), $connection)) {
                    return true;
                }

                warning(sprintf('Skipping file "%s" because the connection "%s" is not valid.', $fileName, $connection));

                return false;
            });

        if ($sortedFiles->isEmpty()) {
            throw new EmptyBaseDirectoryException();
        }

        $filesByConnection = $sortedFiles->groupBy(
        // Supporting the legacy format.
            fn($file) => Arr::get($file, 'meta.database.connection') ?? Arr::get($file, 'meta.connection') ?: static::UNKNOWN_CONNECTION_NAME,
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
        return match (select(
            label: 'Do you want to download and import a fresh dump from the server or an existing dump?',
            options: [
                static::DOWNLOAD_REMOTE_DUMP,
                static::IMPORT_EXISTING_LOCAL_DUMP,
            ],
            default: static::DOWNLOAD_REMOTE_DUMP,
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
            $connectionName = $connectionNames->firstOrFail();

            info(
                sprintf(
                    'Using connection "%s" because there are no dumps created through other connections.',
                    $connectionName
                )
            );

            return $connectionName;
        }

        return select(
            label: 'Import dump for which connection?',
            options: $connectionNames,
        );
    }
}
