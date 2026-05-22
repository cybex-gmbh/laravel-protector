<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\EmptyBaseDirectoryException;
use Cybex\Protector\Exceptions\FileNotFoundException;
use Cybex\Protector\Exceptions\InvalidConnectionException;
use Cybex\Protector\Exceptions\InvalidEnvironmentException;
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
    protected const string UNKNOWN_CONNECTION_LABEL = 'Unknown Connection';

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

    protected const string DOWNLOAD_REMOTE_DUMP = 'Download remote dump';
    protected const string IMPORT_EXISTING_LOCAL_DUMP = 'Import existing dump';
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

        $this->protector = $protectorConfigurator->makeProtector();
        $this->protector->guardRequiredFunctionsEnabled();

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

        $this->runImport($dumpSource, $this->option('force'));

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Reads the remote dump file and deletes all old dumps if the flush option is set.
     */
    protected function getDumpFromRemote(): array
    {
        info(sprintf('Downloading dump from remote server to disk %s, path %s', $this->protector->getLocalDiskName(), $this->protector->getLocalDiskBaseDirectory()));

        $relativeRemoteDumpFilePath = $this->protector->createLocalFilePath();

        $this->protector->download(
            disk: $this->protector->getLocalDisk(),
            filePath: $relativeRemoteDumpFilePath,
        );

        info('Successfully retrieved remote dump.');

        if ($this->option('flush')) {
            $this->protector->flush();
            warning(sprintf('Deleted all old files on disk %s in path %s', $this->protector->getStorageDiskName(), $this->protector->getStorageDiskBaseDirectory()));
        }

        return [
            'path' => $relativeRemoteDumpFilePath,
            'disk' => $this->protector->getLocalDisk(),
            'cleanup' => true,
        ];
    }

    /**
     * Imports a dump from a specific file path.
     * The file path may be either absolute or relative to the dump directory.
     *
     * @throws FileNotFoundException
     */
    protected function getDumpFromFile(): array
    {
        $isAbsoluteFilePath = $this->isAbsolutePath($this->option('file'));

        switch ($isAbsoluteFilePath) {
            case true:
                $dumpFilePath = $this->option('file');

                if (!file_exists($dumpFilePath)) {
                    throw new FileNotFoundException($dumpFilePath);
                }

                return [
                    'path' => $dumpFilePath,
                    'disk' => null,
                    'cleanup' => false,
                ];
            default:
                // This will throw an exception if the dump file was not found.
                $dumpFilePath = $this->protector->getDumpFile($this->option('file'));

                return [
                    'path' => $dumpFilePath,
                    'disk' => null,
                    'cleanup' => false,
                ];
        }
    }

    protected function getLatestDump(): array
    {
        $relativeImportFilePath = $this->protector->getLatestDumpName();

        info(sprintf('Importing %s', $relativeImportFilePath));

        return [
            'path' => $relativeImportFilePath,
            'disk' => null,
            'cleanup' => false,
        ];
    }

    protected function getDumpInteractive(): array
    {
        if ($this->userWantsRemoteDump()) {
            return $this->getDumpFromRemote();
        }

        return $this->chooseImportDump($this->option('connection'));
    }

    /**
     * Returns the file path to a selected dump.
     */
    protected function chooseImportDump(?string $connectionName): array
    {
        $connectionFiles = $this->getConnectionFiles($connectionName)->keys();

        if ($connectionFiles->count() === 1) {
            $relativeImportFilePath = $connectionFiles->first();

            info(sprintf('Using file "%s" because there are no other dumps.', $relativeImportFilePath));
        } else {
            $importFile = select(
                label: 'Which file do you want to import?',
                options: $connectionFiles->mapWithKeys(fn(string $file) => [$file => $file])->toArray(),
            );

            $relativeImportFilePath = $connectionFiles->firstWhere(fn($file) => $file === $importFile);
        }

        return [
            'path' => $relativeImportFilePath,
            'disk' => null,
            'cleanup' => false,
        ];
    }

    /**
     * Imports the selected SQL dump.
     */
    protected function runImport(array $dumpSource, ?bool $optionForce): void
    {
        /** @var string $sourcePath */
        $sourcePath = $dumpSource['path'];
        /** @var ?Filesystem $sourceDisk */
        $sourceDisk = $dumpSource['disk'];
        /** @var bool $cleanup */
        $cleanup = $dumpSource['cleanup'];

        try {
            if ($optionForce || confirm(
                    sprintf(
                        'Are you sure that you want to import the dump into the database: %s?',
                        $this->protector->getDatabaseName()
                    )
                )) {
                spin(fn() => $this->protector->import(
                    $sourcePath,
                    $sourceDisk,
                    noWipe: $this->option('no-wipe'),
                    migrate: $this->option('migrate'),
                    allowProduction: $this->option('allow-production'),
                ), 'Importing dump...');

                info('Import done!');

                return;
            }

            info('Import aborted');
        } finally {
            if ($cleanup && !$this->isAbsolutePath($sourcePath)) {
                ($sourceDisk ?? $this->protector->getStorageDisk())->delete([
                    $sourcePath,
                    $sourcePath . '.meta',
                ]);
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
        $sortedFiles = $this->protector->getDumpFilesWithMetadata()
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

        if ($this->option('ignore-connection-filter')) {
            return $sortedFiles;
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
            $connectionLabel = $this->getConnectionDisplayName($connectionName);

            info(
                sprintf(
                    'Using connection "%s" because there are no dumps created through other connections.',
                    $connectionLabel
                )
            );

            return $connectionName;
        }

        return select(
            label: 'Import dump for which connection?',
            options: $connectionNames->mapWithKeys(fn(string $name) => [$name => $this->getConnectionDisplayName($name)])->toArray(),
        );
    }

    protected function getConnectionDisplayName(string $connectionName): string
    {
        return $connectionName === static::UNKNOWN_CONNECTION_NAME
            ? static::UNKNOWN_CONNECTION_LABEL
            : $connectionName;
    }

    protected function isAbsolutePath(string $filePath): bool
    {
        return str_starts_with($filePath, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $filePath) === 1;
    }
}
