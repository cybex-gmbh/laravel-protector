<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class DownloadDump extends Command
{
    protected $signature = 'protector:download
                {--allow-production : Enable importing SQL dumps on a production system. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. Only works with the --import option. }
                {--d|disk= : A disk to which the dump is written. Default is the storage disk. }
                {--flush-storage : Delete all existing dumps which have a .meta file, except the newly downloaded dump. Only applies to the Protector storage disk, not a disk passed with --disk. }
                {--force : Skips confirmation prompts for import. }
                {--f|file= : The destination file name on the storage disk. }
                {--import : Import the downloaded dump after download. }
                {--m|migrate : Run database migrations after import. }
                {--no-wipe-db : Do not wipe the database before importing the dump. }';

    protected $description = 'Downloads a database dump to the configured storage disk.';

    protected Protector $protector;

    public function handle(): int
    {
        if ($this->option('disk') && $this->option('flush-storage')) {
            error('The --flush-storage option cannot be used with the --disk option.');

            return self::FAILURE;
        }

        $this->configureProtector();

        $shouldImport = $this->option('import') && $this->confirmImport();

        $targetDisk = $this->option('disk') ? Storage::disk($this->option('disk')) : DiskHelper::getStorageDisk();

        $fileName = spin(
            callback: fn() => $shouldImport
                ? $this->protector->downloadAndImport(
                    targetFileName: $this->option('file'),
                    targetDisk: $targetDisk,
                    wipeDb: !$this->option('no-wipe-db'),
                    migrate: $this->option('migrate'),
                    allowProduction: $this->option('allow-production'),
                )
                : $this->protector->download(targetFileName: $this->option('file'), targetDisk: $targetDisk),
            message: $shouldImport ? 'Downloading and importing...' : 'Downloading dump...'
        );

        info('Successfully downloaded dump to disk.');
        $shouldImport && info('Import done!');

        if ($this->option('flush-storage')) {
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
        DiskHelper::flushStorage(excludeFile: $fileName);

        warning('Storage directory has been flushed. Downloaded dump was retained.');
    }
}
