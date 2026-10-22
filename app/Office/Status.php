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
            self::Working => 'Aan het werk',
            self::Waiting => 'Wacht op jou',
            self::Done => 'Klaar',
            self::Error => 'Storing',
            self::Idle => 'Beschikbaar',
            self::Sleeping => 'Gepland',
            self::Offline => 'Naar huis',
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
