<?php

namespace Cybex\Protector\Commands;

use Cybex\Protector\Classes\Cleanup\CleanupLocalInvokable;
use function Laravel\Prompts\info;

/**
 * Class CleanupLocal
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
    protected $signature = 'protector:cleanup-local';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes all of the Protector\'s temporary files on the protector_local disk which are older than 1 day.';

    protected function executeCommand(): int
    {
        app()->call(CleanupLocalInvokable::class);

        info('Success!');

        return self::SUCCESS;
    }
}
