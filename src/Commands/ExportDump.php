<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Http\File;

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
     */
    public function handle(): int
    {
        $this->protector = app('protector');

        $this->protector->guardExecEnabled();

        $fileName = $this->option('file') ?: $this->protector->createFilename();
        $directory = $this->protector->getBaseDirectory();

        if ($this->option('connection')) {
            $connectionName = $this->option('connection');
        }

        $options = [];
        $options['no-data'] = $this->option('no-data') ?: false;

        $this->protector->withConnectionName($connectionName ?? null);

        $tempFilePath = $this->protector->createDump($options);

        $this->protector->getDisk()->putFileAs($directory, new File($tempFilePath), $fileName);
        unlink($tempFilePath);

        $this->info(sprintf('Dump <comment>%s</> was created in <comment>%s</>', $fileName, $this->protector->getDisk()->path($directory)));

        return self::SUCCESS;
    }
}
