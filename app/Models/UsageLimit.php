<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UsageLimit extends Model
{
    public const CLAUDE = 'claude';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'five_hour_used' => 'float',
            'five_hour_resets_at' => 'datetime',
            'seven_day_used' => 'float',
            'seven_day_resets_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->where('key', self::CLAUDE)->first();
    }

    /**
     * @param  array{five_hour?: array|null, seven_day?: array|null}  $limits
     */
    public static function record(array $limits): array
    {
        $limit = static::query()->firstOrNew(['key' => self::CLAUDE]);
        $before = $limit->exists ? $limit->toOffice() : null;

        foreach (['five_hour', 'seven_day'] as $window) {
            if (! isset($limits[$window]['used_percentage'])) {
                continue;
            }

            $limit->{"{$window}_used"} = round(min(100, max(0, (float) $limits[$window]['used_percentage'])), 2);
            $limit->{"{$window}_resets_at"} = self::moment($limits[$window]['resets_at'] ?? null);
        }

        $limit->reported_at = now();
        $limit->save();

        return [$limit, self::changed($before, $limit->toOffice())];
    }

    protected static function changed(?array $before, array $after): bool
    {
        if (! $before) {
            return true;
        }

        unset($before['reported_at'], $after['reported_at']);

        return $before != $after;
    }

    protected static function moment(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
    }

    protected function window(?float $used, ?CarbonInterface $resetsAt): ?array
    {
        if ($used === null) {
            return null;
        }

        return [
            'used' => (int) round($used),
            'resets_at' => $resetsAt?->toIso8601String(),
        ];
    }

    public function toOffice(): array
    {
        return [
            'week' => $this->window($this->seven_day_used, $this->seven_day_resets_at),
            'five_hour' => $this->window($this->five_hour_used, $this->five_hour_resets_at),
            'reported_at' => $this->reported_at?->toIso8601String(),
        ];
    }
}
