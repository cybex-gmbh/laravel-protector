<?php

namespace Cybex\Protector\Enums;

use Illuminate\Support\Facades\Artisan;

enum ExecutionMode: string
{
    case SCHEDULE = 'schedule';
    case SYNC = 'sync';


    public static function fromConfig(): ExecutionMode
    {
        return ExecutionMode::from(config('protector.cleanup.local_disk.mode'));
    }

    public function shouldSchedule(): bool
    {
        return match ($this) {
            self::SCHEDULE => true,
            default => false,
        };
    }

    public function execute(string $commandClass, ?string $schedule = null): void
    {
        match ($this) {
            self::SYNC => $this->executeSync($commandClass),
            self::SCHEDULE => $this->executeSchedule($commandClass, $schedule)
        };
    }

    protected function executeSync(string $command): void
    {
        Artisan::call($command);
    }

    protected function executeSchedule(string $command, string $schedule): void
    {
        Artisan::resolveConsoleSchedule()
            ->command($command)
            ->cron($schedule)
            ->runInBackground();
    }
}
