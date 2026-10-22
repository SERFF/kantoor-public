<?php

namespace App\Office;

enum Status: string
{
    case Working = 'working';
    case Waiting = 'waiting';
    case Done = 'done';
    case Error = 'error';
    case Idle = 'idle';
    case Sleeping = 'sleeping';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Working => 'Working',
            self::Waiting => 'Waiting for you',
            self::Done => 'Done',
            self::Error => 'Stuck',
            self::Idle => 'Available',
            self::Sleeping => 'Scheduled',
            self::Offline => 'Gone home',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Working || $this === self::Waiting;
    }

    public static function reportable(): array
    {
        return array_map(fn (self $status) => $status->value, [
            self::Working, self::Waiting, self::Done, self::Error, self::Idle, self::Sleeping,
        ]);
    }
}
