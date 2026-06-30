<?php

namespace Cybex\Protector\Commands;

use Closure;
use Illuminate\Console\Command;
use Throwable;
use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;

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

    /**
     * Wrapper for the `spin` function.
     * Resets the shell output state, since the spinner uses \r, which can mess with the output.
     */
    protected function spinner(Closure $callback, string $message = ''): mixed
    {
        $this->newLine();

        $result = spin(
            callback: $callback,
            message: $message
        );

        if ($this->hasOption('migrate') && $this->option('migrate')) {
            $this->newLine();
        }

        return $result;
    }

    abstract protected function executeCommand(): int;
}
