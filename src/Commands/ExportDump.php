<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
use Illuminate\Console\Command;
use Illuminate\Http\File;

/**
 * Class ExportDump
 * @package Cybex\Protector\Commands;
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
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->protector = app('protector');
        $fileName  = $this->option('file') ?: $this->protector->createFilename();
        $directory = $this->protector->getBaseDirectory();

        if ($this->option('connection')) {
            $connectionName = $this->option('connection');
        }

        $options            = [];
        $options['no-data'] = $this->option('no-data') ?: false;

        if ($this->protector->configure($connectionName ?? null)) {
            $tempFilePath = $this->protector->createDump($options);

            $this->protector->getDisk()->putFileAs($directory, new File($tempFilePath), $fileName);
            unlink($tempFilePath);

            $this->info(sprintf('Dump %s was created in %s', $fileName, $directory));
        } else {
            $this->error('Configuration is invalid.');
        }
    }
}
