<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Protector;
use Illuminate\Console\Command;

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
                {--c|connection= : The configured database-connection in Laravels config/database.php. }
                {--no-data : Exclude data from dump.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exports a dump of the current Database including Data as backup.';

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
     * @return mixed
     */
    public function handle()
    {
        $configuration = [
            // Set command as source, so the helper can use it to output information.
            'command' => $this,
        ];

        $fileName = $this->option('file') ?: '';

        if ($this->option('connection')) {
            $configuration['connection'] = $this->option('connection');
        }

        $options            = [];
        $options['no-data'] = $this->option('no-data') ?: false;

        $protector = new Protector();

        if ($protector->configure($configuration)) {
            // Create the desired dump.
            $filePath = $protector->createDump($fileName, $options);
            $this->info(sprintf('Dump was created at %s', $filePath));
        } else {
            $this->error('Configuration is invalid.');
        }
    }
}
