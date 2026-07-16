<?php

namespace Cybex\Protector\Enums;

enum FlushMode: string
{
    case SCHEDULE = 'schedule';
    case SYNC = 'sync';

    public static function getConfiguredMode(): FlushMode
    {
        return FlushMode::from(config('protector.automatic_flush.local.mode'));
    }

    public static function getConfiguredCron(): string
    {
        return config('protector.automatic_flush.local.cron');
    }

    public function shouldSchedule(): bool
    {
        return match ($this) {
            self::SCHEDULE => true,
            self::SYNC => false,
        };
    }
}
