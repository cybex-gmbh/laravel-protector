<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Contracts\ProtectorConfiguratorContract;
use Cybex\Protector\Exceptions\ShellAccessDeniedException;
use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

/**
 * Class ExportDump
 */
class ExportDump extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:export
                {--f|file= : The destination file name of the SQL export. }
                {--c|connection= : The configured database-connection in Laravel\'s config/database.php. }
                {--no-data : Exclude data from dump.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exports a dump of the current database including data as backup.';

    protected ?Protector $protector = null;

    /**
     * Execute the console command.
     *
     * @throws ShellAccessDeniedException
     */
    public function handle(): int
    {
        $protectorConfigurator = app(ProtectorConfiguratorContract::class);

        if ($this->option('no-data')) {
            $protectorConfigurator->withoutData();
        }

        if ($this->option('connection')) {
            $protectorConfigurator->setConnectionName($this->option('connection'));
        }

        $this->protector = $protectorConfigurator->makeProtector();
        $this->protector->guardRequiredFunctionsEnabled();

        spin(
            callback: fn() => $this->protector->export(filePath: $this->option('file')),
            message: 'Exporting dump...'
        );

        info('Exported dump!');

        return self::SUCCESS;
    }
}
