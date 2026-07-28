<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;
use function Laravel\Prompts\info;

/**
 * Class CleanupStorage
 *
 * @package Cybex\Protector\Commands;
 */
class CleanupStorage extends AbstractCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'protector:cleanup-storage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes all files on the protector_storage disk which have a corresponding .meta file.';

    protected function executeCommand(): int
    {
        DiskHelper::cleanupStorage();

        info('Success!');

        return self::SUCCESS;
    }
}
