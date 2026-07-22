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

    public function execute(string $invokable, ?string $schedule = null): void
    {
        match ($this) {
            self::SYNC => $this->executeSync($invokable),
            self::SCHEDULE => $this->executeSchedule($invokable, $schedule)
        };
    }

    protected function executeSync(string $invokable): void
    {
        app()->call($invokable);
    }

    protected function executeSchedule(string $invokable, string $schedule): void
    {
        Artisan::resolveConsoleSchedule()
            ->call($invokable)
            ->cron($schedule);
    }
}
