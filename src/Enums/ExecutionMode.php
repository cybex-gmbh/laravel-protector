<?php

namespace Cybex\Protector\Enums;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Throwable;

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

    public function run(string $invokable, ?string $schedule = null): bool
    {
        $success = match ($this) {
            self::SYNC => $this->execute($invokable),
            self::SCHEDULE => $this->schedule($invokable, $schedule)
        };

        if (!$success) {
            Log::error("Failed to run cleanup invokable: {$invokable} in mode: {$this->value}");
        }

        return $success;
    }

    protected function execute(string $invokable): bool
    {
        return app()->call($invokable);
    }

    protected function schedule(string $invokable, string $schedule): bool
    {
        try {
            Schedule::call($invokable)->cron($schedule);
        } catch (Throwable $t) {
            return false;
        }

        return true;
    }
}
