<?php

namespace Cybex\Protector\Commands;

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
        $protector = app('protector');
        $fileName  = $this->option('file') ?: $protector->createFilename();

        if ($this->option('connection')) {
            $connectionName = $this->option('connection');
        }

        $options            = [];
        $options['no-data'] = $this->option('no-data') ?: false;

        if ($protector->configure($connectionName ?? null)) {
            $tempFilePath     = $protector->createDump($options);
            $relativeFilePath = $protector->getDumpFilePath($fileName);

            $protector->getDisk()->putFileAs(dirname($relativeFilePath), new File($tempFilePath), basename($relativeFilePath));
            unlink($tempFilePath);

            $this->info(sprintf('Dump was created at %s', $protector->getDisk()->path($relativeFilePath)));
        } else {
            $this->error('Configuration is invalid.');
        }
    }
}
