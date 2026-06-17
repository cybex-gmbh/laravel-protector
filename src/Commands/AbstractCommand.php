<?php

namespace Cybex\Protector\Commands;

use Illuminate\Console\Command;
use Throwable;
use function Laravel\Prompts\error;

/**
 * Class AbstractCommand
 * @package Cybex\Protector\Commands;
 */
abstract class AbstractCommand extends Command
{
    /**
     * Execute the console command.
     *
     * @return int
     * @throws Throwable
     */
    public function handle(): int
    {
        try {
            return $this->executeCommand();
        } catch (Throwable $throwable) {
            error($throwable->getMessage());

            // Re-throw exceptions when running tests for more specific testing.
            if (app()->runningUnitTests()) {
                throw $throwable;
            }

            return self::FAILURE;
        }
    }

    abstract protected function executeCommand(): int;
}
