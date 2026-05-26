<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Facades\DumpFileManagerFacade as DumpFileManager;
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
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
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

        $filePath = spin(
            callback: fn() => $this->protector->download(filePath: $this->option('file')),
            message: 'Downloading dump...'
        );

        info('Successfully downloaded dump to disk.');

        if ($this->option('flush')) {
            DumpFileManager::flushDumps(excludeFile: $filePath);

            warning('Storage directory has been flushed. Downloaded dump was retained.');
        }

        if ($this->option('import')) {
            return $this->import($filePath);
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

    /**
     * @param string $filePath
     * @return int
     */
    protected function import(string $filePath): int
    {
        if ($this->option('force') || confirm(sprintf('Import downloaded dump into the database: %s?', $this->protector->getDatabaseName()))) {
            spin(
                callback: fn() => $this->protector->import(
                    $filePath,
                    noWipe: $this->option('no-wipe'),
                    migrate: $this->option('migrate'),
                    allowProduction: $this->option('allow-production'),
                ),
                message: 'Importing...'
            );

            info('Import done!');

            return self::SUCCESS;
        }

        info('Import aborted');

        return self::SUCCESS;
    }
}


