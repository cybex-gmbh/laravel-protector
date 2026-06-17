<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;

/**
 * Class FlushStorage
 *
 * @package Cybex\Protector\Commands;
 */
class FlushStorage extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:flush-storage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes all files on the protector_storage disk which have a corresponding .meta file.';

    protected function executeCommand(): int
    {
        DiskHelper::flushStorage();

        return self::SUCCESS;
    }
}
