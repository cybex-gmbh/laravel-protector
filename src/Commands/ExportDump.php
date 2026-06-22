<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use Cybex\Protector\Protector;
use Illuminate\Support\Facades\Storage;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

/**
 * Class ExportDump
 */
class ExportDump extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:export
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
                {--d|disk= : A disk to which the dump is written. Default is the storage disk. }
                {--f|file= : The destination file name on the storage disk. }
                {--no-copy : Do not create a copy of the file on the local disk. The storage disk must be available on the local filesystem. }
                {--no-data : Exclude data from dump. }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exports a dump of the current database including data as backup.';

    protected ?Protector $protector = null;

    /**
     * @throws ShellAccessDeniedException
     */
    protected function executeCommand(): int
    {
        $this->guard();
        $this->configureProtector();

        $targetDisk = $this->option('disk') ? Storage::disk($this->option('disk')) : DiskHelper::getStorageDisk();

        spin(
            callback: fn() => $this->protector->export(targetFileName: $this->option('file'), targetDisk: $targetDisk, copy: !$this->option('no-copy')),
            message: 'Exporting dump...'
        );

        info('Exported dump!');

        return self::SUCCESS;
    }

    /**
     * @throws ShellAccessDeniedException
     */
    protected function guard(): void
    {
        app('protector')->validateSystemRequirements();
    }

    protected function configureProtector(): void
    {
        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('no-data')) {
            $protectorConfigurator->withoutData();
        }

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->makeProtector();
    }
}
