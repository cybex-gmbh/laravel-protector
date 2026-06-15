<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class DownloadDump extends Command
{
    protected $signature = 'protector:download
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. Only works with the --import option. }
                {--d|disk= : A disk to which the dump is written. Default is the storage disk. }
                {--flush : Delete all existing dumps which have a .meta file, except the newly downloaded dump. Only applies to the Protector storage disk, not a disk passed with --disk. }
                {--force : Skips confirmation prompts for import. }
                {--f|file= : The destination file name on the storage disk. }
                {--import : Import the downloaded dump after download. }
                {--m|migrate : Run database migrations after import. }
                {--w|no-wipe : Do not wipe the database before importing the dump. }';

    protected $description = 'Downloads a database dump to the configured storage disk.';

    protected Protector $protector;

    public function handle(): int
    {
        $this->configureProtector();

        $shouldImport = $this->option('import') && $this->confirmImport();

        $storageDisk = $this->option('disk') ? Storage::disk($this->option('disk')) : DiskHelper::getStorageDisk();

        $fileName = spin(
            callback: fn() => $shouldImport
                ? $this->protector->downloadAndImport(
                    storageFileName: $this->option('file'),
                    storageDisk: $storageDisk,
                    wipe: !$this->option('no-wipe'),
                    migrate: $this->option('migrate'),
                    allowProduction: $this->option('allow-production'),
                )
                : $this->protector->download(storageFileName: $this->option('file'), storageDisk: $storageDisk),
            message: $shouldImport ? 'Downloading and importing...' : 'Downloading dump...'
        );

        info('Successfully downloaded dump to disk.');
        $shouldImport && info('Import done!');

        if ($this->option('flush')) {
            $this->flush($fileName);
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

    protected function flush(string $fileName): void
    {
        DiskHelper::flushDumps(excludeFile: $fileName);

        warning('Storage directory has been flushed. Downloaded dump was retained.');
    }
}
