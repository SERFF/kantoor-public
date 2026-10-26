<?php

namespace App\Models;

use App\Office\UsageProjection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UsageLimit extends Model
{
    public const CLAUDE = 'claude';

    public const WINDOWS = [
        'five_hour' => 5 * 3600,
        'seven_day' => 7 * 24 * 3600,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'five_hour_used' => 'float',
            'five_hour_resets_at' => 'datetime',
            'five_hour_resets_estimated' => 'boolean',
            'seven_day_used' => 'float',
            'seven_day_resets_at' => 'datetime',
            'seven_day_resets_estimated' => 'boolean',
            'measured_at' => 'datetime',
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
    public static function record(array $limits, mixed $measuredAt = null, ?string $source = null): array
    {
        $limit = static::query()->firstOrNew(['key' => self::CLAUDE]);
        $before = $limit->exists ? $limit->toOffice() : null;
        $measuredAt = self::measuredAt($measuredAt);

        UsageSample::remember(
            $measuredAt,
            isset($limits['five_hour']['used_percentage']) ? (float) $limits['five_hour']['used_percentage'] : null,
            isset($limits['seven_day']['used_percentage']) ? (float) $limits['seven_day']['used_percentage'] : null,
        );

        if ($limit->measured_at && $measuredAt->lt($limit->measured_at)) {
            return [$limit, false];
        }

        foreach (self::WINDOWS as $window => $length) {
            if (! isset($limits[$window]['used_percentage'])) {
                continue;
            }

            $limit->{"{$window}_used"} = round(min(100, max(0, (float) $limits[$window]['used_percentage'])), 2);
            $limit->applyReset(
                $window,
                $length,
                self::moment($limits[$window]['resets_at'] ?? null),
                (bool) ($limits[$window]['resets_estimated'] ?? false),
                $measuredAt,
            );
        }

        $limit->measured_at = $measuredAt;
        $limit->source = $source ?? $limit->source;
        $limit->reported_at = now();
        $limit->save();

        return [$limit, self::changed($before, $limit->toOffice())];
    }

    protected function applyReset(string $window, int $length, ?CarbonInterface $reset, bool $estimated, CarbonInterface $measuredAt): void
    {
        if ($reset && ! self::plausible($reset, $length, $measuredAt)) {
            $reset = null;
        }

        $stored = $this->{"{$window}_resets_at"};
        $storedExact = $stored && ! $this->{"{$window}_resets_estimated"};

        if ($storedExact && $stored->lte($measuredAt) && $window === 'seven_day') {
            $stored = self::rollForward($stored, $length, $measuredAt);
            $this->{"{$window}_resets_at"} = $stored;
        }

        if ($reset && ($estimated && $storedExact && $stored->gt($measuredAt))) {
            return;
        }

        if ($reset) {
            $this->{"{$window}_resets_at"} = $reset;
            $this->{"{$window}_resets_estimated"} = $estimated;

            return;
        }

        if ($stored && $stored->lte($measuredAt)) {
            $this->{"{$window}_resets_at"} = $window === 'seven_day' ? self::rollForward($stored, $length, $measuredAt) : null;
        }
    }

    protected static function plausible(CarbonInterface $reset, int $length, CarbonInterface $measuredAt): bool
    {
        return $reset->getTimestamp() >= $measuredAt->getTimestamp() - 60
            && $reset->getTimestamp() <= $measuredAt->getTimestamp() + $length + 86400;
    }

    protected static function rollForward(CarbonInterface $reset, int $length, CarbonInterface $after): CarbonInterface
    {
        $behind = $after->getTimestamp() - $reset->getTimestamp();
        $steps = intdiv(max(0, $behind), $length) + 1;

        return Carbon::createFromTimestamp($reset->getTimestamp() + $steps * $length);
    }

    protected static function measuredAt(mixed $value): CarbonInterface
    {
        $moment = self::moment($value);

        return $moment && $moment->lte(now()) ? $moment : now();
    }

    protected static function changed(?array $before, array $after): bool
    {
        if (! $before) {
            return true;
        }

        unset($before['reported_at'], $after['reported_at'], $before['week']['projected'], $after['week']['projected'], $before['week']['empty_at'], $after['week']['empty_at']);

        return $before != $after;
    }

    protected static function moment(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
    }

    protected function window(?float $used, ?CarbonInterface $resetsAt, ?bool $estimated): ?array
    {
        if ($used === null) {
            return null;
        }

        return [
            'used' => (int) round($used),
            'resets_at' => $resetsAt?->toIso8601String(),
            'estimated' => $resetsAt ? (bool) $estimated : false,
        ];
    }

    public static function rememberHistory(array $history): void
    {
        foreach ($history as $sample) {
            UsageSample::remember(
                Carbon::createFromTimestamp((int) $sample['measured_at']),
                isset($sample['five_hour']) ? (float) $sample['five_hour'] : null,
                isset($sample['seven_day']) ? (float) $sample['seven_day'] : null,
            );
        }

        UsageSample::prune();
    }

    public function toOffice(): array
    {
        $week = $this->window($this->seven_day_used, $this->seven_day_resets_at, $this->seven_day_resets_estimated);

        if ($week) {
            $week += UsageProjection::make()->week($this->seven_day_used, $this->seven_day_resets_at)
                ?? ['projected' => null, 'empty_at' => null];
        }

        return [
            'week' => $week,
            'five_hour' => $this->window($this->five_hour_used, $this->five_hour_resets_at, $this->five_hour_resets_estimated),
            'measured_at' => $this->measured_at?->toIso8601String(),
            'source' => $this->source,
            'reported_at' => $this->reported_at?->toIso8601String(),
        ];
    }
}
