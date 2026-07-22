<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;

/**
 * Class FlushStorage
 *
 * @package Cybex\Protector\Commands;
 */
class CleanupLocal extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:flush-local';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes all of the Protector\'s temporary files on the protector_local disk which are older than 1 day.';

    protected function executeCommand(): int
    {
        DiskHelper::flushOldLocalFiles();

        return self::SUCCESS;
    }
}
