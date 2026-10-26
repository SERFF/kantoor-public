<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class UsageSample extends Model
{
    public const KEEP_DAYS = 35;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'five_hour_used' => 'float',
            'seven_day_used' => 'float',
        ];
    }

    public static function remember(CarbonInterface $measuredAt, ?float $fiveHour, ?float $sevenDay): void
    {
        if ($fiveHour === null && $sevenDay === null) {
            return;
        }

        if ($measuredAt->lt(now()->subDays(self::KEEP_DAYS)) || $measuredAt->gt(now())) {
            return;
        }

        static::query()->insertOrIgnore([
            'measured_at' => $measuredAt->copy()->utc()->toDateTimeString(),
            'five_hour_used' => $fiveHour === null ? null : round(min(100, max(0, $fiveHour)), 2),
            'seven_day_used' => $sevenDay === null ? null : round(min(100, max(0, $sevenDay)), 2),
        ]);
    }

    public static function prune(): void
    {
        static::query()->where('measured_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
