<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyDumpDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;
use function is_null;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Class ImportDump
 *
 * @package Cybex\Protector\Commands
 */
class ImportDump extends AbstractCommand
{
    protected const string UNKNOWN_CONNECTION_NAME = 'unknown_connection';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:import
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
                {--d|disk= : A disk from which the dump is read. Only applicable with the --file option. Default is the storage disk. }
                {--force : Skips confirmation prompts. Requires the file, remote or latest option. }
                {--f|file= : A file name on the storage disk. }
                {--l|latest : Import the most recent dump available in the configured dumps directory. }
                {--m|migrate : Run database migrations after import. }
                {--no-copy : Do not create a copy of the file on the local disk. Only applicable with the --file option. The passed file must be available on a local disk. }
                {--r|remote : Pull a fresh dump from the remote server as configured in the .env file. Will be used as fallback when combined with other options. }
                {--no-wipe-db : Do not wipe the database before importing the dump. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports a local or remote database dump.';

    protected const string DOWNLOAD_REMOTE_DUMP = 'Download remote dump';
    protected const string IMPORT_EXISTING_LOCAL_DUMP = 'Import existing dump';
    protected Protector $protector;
    protected Filesystem $disk;
    protected bool $fileIsValid = false;
    protected bool $shouldImportRemoteDump = false;

    /**
     * @throws FileNotFoundException
     * @throws EmptyDumpDirectoryException
     * @throws ShellAccessDeniedException
     * @throws InvalidConnectionException
     * @throws InvalidEnvironmentException
     * @throws Throwable
     */
    protected function executeCommand(): int
    {
        $this->disk = $this->option('disk') ? Storage::disk($this->option('disk')) : DiskHelper::getStorageDisk();

        $this->guard();
        $this->configureProtector();
        $this->confirmImport();

        $dumpSource = match (true) {
            $this->option('remote') => $this->shouldImportRemoteDump = true,
            $this->fileIsValid => $this->option('file'),
            $this->option('latest') => $this->getLatestDump(),
            default => $this->getDumpInteractive(),
        };

        if ($this->shouldImportRemoteDump) {
            return $this->importRemoteDump();
        }

        $this->runImport($dumpSource);

        return self::SUCCESS;
    }

    protected function importRemoteDump(): int
    {
        $dumpName = DiskHelper::createLocalFileName();

        try {
            spin(
                callback: fn() => $this->protector->downloadAndImport(targetFileName: $dumpName, targetDisk: DiskHelper::getLocalDisk()),
                message: 'Downloading and importing...'
            );

            info('Successfully retrieved and imported remote dump.');
        } finally {
            DiskHelper::deleteLocalFile($dumpName);
        }

        return self::SUCCESS;
    }

    /**
     * @throws EmptyDumpDirectoryException
     */
    protected function getLatestDump(): string
    {
        $dumpName = $this->protector->latestDumpName();

        info(sprintf('Importing %s', $dumpName));

        return $dumpName;
    }

    /**
     * @throws EmptyDumpDirectoryException
     * @throws InvalidConnectionException
     */
    protected function getDumpInteractive(): string
    {
        if ($this->userWantsRemoteDump()) {
            $this->shouldImportRemoteDump = true;

            return self::SUCCESS;
        }

        return $this->chooseImportDump($this->option('connection'));
    }

    /**
     * Returns the file name of the selected dump.
     *
     * @throws EmptyDumpDirectoryException
     * @throws InvalidConnectionException
     */

    protected function chooseImportDump(?string $connectionName): string
    {
        $connectionFiles = $this->getConnectionFiles($connectionName)->keys();

        if ($connectionFiles->count() === 1) {
            $dumpName = $connectionFiles->first();

            info(sprintf('Using file "%s" because there are no other dumps.', $dumpName));

            return $dumpName;
        }

        $selectedFile = select(
            label: 'Which file do you want to import?',
            options: $connectionFiles,
        );

        return $connectionFiles->filter(fn($file) => $file === $selectedFile)->first();
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function runImport(string $dumpName): void
    {
        spin(
            callback: fn() => $this->protector->import(
                sourceFilePath: $dumpName,
                sourceDisk: $this->disk,
                wipeDb: !$this->option('no-wipe-db'),
                migrate: $this->option('migrate'),
                allowProduction: $this->option('allow-production'),
                copy: !$this->option('no-copy'),
            ),
            message: 'Importing dump...'
        );

        info('Import done!');
    }

    /**
     * Returns a list of either all dumps or those for the specified connection name.
     *
     * @throws InvalidConnectionException|EmptyDumpDirectoryException
     */
    protected function getConnectionFiles(?string $connectionName = null): Collection
    {
        $sortedFiles = DiskHelper::allStorageFilesWithMetadata()
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
            throw new EmptyDumpDirectoryException();
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

    /**
     * @throws FileNotFoundException
     * @throws InvalidEnvironmentException
     * @throws ShellAccessDeniedException
     * @throws Throwable
     */
    protected function guard(): void
    {
        app('protector')->validateSystemRequirements();
        $this->validateSource();
        $this->validateEnvironment();
    }

    /**
     * @throws Throwable
     */
    protected function validateSource(): void
    {
        count(
            array_filter([
                $this->option('remote'),
                !is_null($this->option('file')),
                $this->option('latest')
            ])
        ) > 1 && $this->fail('You can only specify one of the following options: --remote, --file, --latest.');

        $this->validateFile();

        if ($this->option('force') && !($this->option('remote') || $this->fileIsValid || $this->option('latest'))) {
            $this->fail('Nothing to import. You need to specify either --remote, --file, or --latest.');
        }

        if (($this->option('disk') || $this->option('no-copy')) && !$this->fileIsValid) {
            $this->fail('When using --disk or the --no-copy option, --file needs to be specified.');
        }
    }

    /**
     * @throws Throwable
     * @throws FileNotFoundException
     */
    protected function validateFile(): void
    {
        if (!is_null($this->option('file'))) {
            if (trim($this->option('file')) === '') {
                $this->fail('The --file option cannot be empty. Please provide a valid file name.');
            }

            $this->disk->exists($this->option('file')) || throw new FileNotFoundException($this->option('file'));
            $this->fileIsValid = true;
        }
    }

    /**
     * @throws InvalidEnvironmentException
     */
    protected function validateEnvironment(): void
    {
        if (App::environment('production') && !$this->option('allow-production')) {
            throw new InvalidEnvironmentException(
                'Import is not allowed on production systems! Use --allow-production'
            );
        }
    }

    protected function configureProtector(): void
    {
        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->makeProtector();
    }

    /**
     * @throws Throwable
     */
    protected function confirmImport(): void
    {
        if (!$this->option('force') && !confirm(
                sprintf(
                    'Are you sure that you want to import a dump into the database: %s?',
                    $this->protector->getDatabaseName()
                )
            )
        ) {
            $this->fail('Import aborted.');
        }
    }
}
