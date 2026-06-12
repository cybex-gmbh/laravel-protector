<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class DownloadDump extends Command
{
    protected $signature = 'protector:download
                {--f|file= : The destination file path on the storage disk. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. Only works with the --import option. }
                {--flush : Delete all existing dumps except the newly downloaded dump. }
                {--import : Import the downloaded dump after download. }
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--m|migrate : Run database migrations after import. }
                {--w|no-wipe : Do not wipe the database before importing the dump. }
                {--force : Skips confirmation prompts for import. }';

    protected $description = 'Downloads a database dump to the configured storage disk.';

    protected Protector $protector;

    public function handle(): int
    {
        $this->configureProtector();

        $shouldImport = $this->option('import') && $this->confirmImport();

        $filePath = spin(
            callback: fn() => $shouldImport
                ? $this->protector->downloadAndImport(
                    storageFilePath: $this->option('file'),
                    noWipe: $this->option('no-wipe'),
                    migrate: $this->option('migrate'),
                    allowProduction: $this->option('allow-production'),
                )
                : $this->protector->download(storageFilePath: $this->option('file')),
            message: $shouldImport ? 'Downloading and importing...' : 'Downloading dump...'
        );

        info('Successfully downloaded dump to disk.');
        $shouldImport && info('Import done!');

        if ($this->option('flush')) {
            $this->flush($filePath);
        }

        return self::SUCCESS;
    }

    protected function configureProtector(): void
    {
        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->makeProtector();
    }

    protected function confirmImport(): bool
    {
        $shouldImport = $this->option('force') || confirm(
                sprintf('Import downloaded dump into the database: %s?', $this->protector->getDatabaseName())
            );

        if (!$shouldImport) {
            info('Import aborted');
        }

        return $shouldImport;
    }

    protected function flush(string $filePath): void
    {
        DiskHelper::flushDumps(excludeFile: $filePath);

        warning('Storage directory has been flushed. Downloaded dump was retained.');
    }
}
