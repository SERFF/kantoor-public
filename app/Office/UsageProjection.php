<?php

namespace App\Office;

use App\Models\UsageSample;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class UsageProjection
{
    public const WEEK = 7 * 24 * 3600;

    public const PRIORS = [
        'night' => 0.02,
        'work' => 0.45,
        'evening' => 0.12,
        'weekend' => 0.10,
    ];

    public const MAX_RATE = 10.0;

    public const FULL_WEIGHT_HOURS = 40.0;

    public const MOMENTUM_SECONDS = 3 * 3600;

    public const LEARN_DAYS = 28;

    private array $learned = [];

    public function __construct(private readonly string $timezone) {}

    public static function make(): self
    {
        return new self(config('kantoor.timezone'));
    }

    /**
     * @return array{projected: int, empty_at: ?string}|null
     */
    public function week(?float $used, ?CarbonInterface $resetsAt, ?CarbonInterface $now = null): ?array
    {
        $now = CarbonImmutable::instance($now ?? now());

        if ($used === null || ! $resetsAt || $resetsAt->lte($now)) {
            return null;
        }

        $start = $now->getTimestamp();
        $end = $resetsAt->getTimestamp();
        $samples = UsageSample::query()
            ->where('measured_at', '>=', $now->subDays(self::LEARN_DAYS))
            ->where('measured_at', '<=', $now)
            ->whereNotNull('seven_day_used')
            ->orderBy('measured_at')
            ->get(['measured_at', 'seven_day_used'])
            ->map(fn (UsageSample $s) => ['t' => $s->measured_at->getTimestamp(), 'used' => $s->seven_day_used]);

        $this->learn($samples);

        $momentum = 0.0;
        $recent = $this->recentRate($samples, $start);
        if ($recent !== null) {
            $horizon = min(self::MOMENTUM_SECONDS, $end - $start);
            $momentum = max(0.0, $recent * $horizon - $this->integrate($start, $start + $horizon)) / max(1, $horizon);
        }

        $elapsed = self::WEEK - ($end - $start);
        $sanity = $elapsed > 0 && $used > 0 ? ($used / $elapsed) * 0.10 : 0.0;

        $total = $used;
        $emptyAt = null;
        for ($cursor = $start; $cursor < $end; $cursor = $step) {
            $step = min($end, $cursor + 3600 - ($cursor % 3600));
            $seconds = $step - $cursor;
            $extra = $cursor < $start + self::MOMENTUM_SECONDS ? $momentum : 0.0;
            $rate = $this->expected($this->bucket($cursor)) / 3600 + $extra + $sanity;
            $next = $total + $rate * $seconds;

            if ($emptyAt === null && $total < 100 && $next >= 100 && $rate > 0) {
                $emptyAt = $cursor + (int) ((100 - $total) / $rate);
            }

            $total = $next;
        }

        return [
            'projected' => (int) round(min(100, max($used, $total))),
            'empty_at' => $emptyAt ? CarbonImmutable::createFromTimestamp($emptyAt)->toIso8601String() : null,
        ];
    }

    public function bucket(int $timestamp): string
    {
        $local = CarbonImmutable::createFromTimestamp($timestamp, $this->timezone);

        return match (true) {
            $local->hour < 7 => 'night',
            $local->isWeekend() => 'weekend',
            $local->hour >= 9 && $local->hour < 18 => 'work',
            default => 'evening',
        };
    }

    public function expected(string $bucket): float
    {
        $prior = self::PRIORS[$bucket];
        $learned = $this->learned[$bucket] ?? null;

        if (! $learned || $learned['hours'] <= 0) {
            return $prior;
        }

        $weight = min(1.0, $learned['hours'] / self::FULL_WEIGHT_HOURS);

        return $prior * (1 - $weight) + ($learned['used'] / $learned['hours']) * $weight;
    }

    private function learn(Collection $samples): void
    {
        $this->learned = [];

        foreach ($samples->sliding(2) as $pair) {
            [$a, $b] = $pair->values()->all();
            $seconds = $b['t'] - $a['t'];
            $delta = $b['used'] - $a['used'];

            if ($seconds <= 0 || $seconds > 3 * 86400 || $delta < 0 || $delta / ($seconds / 3600) > self::MAX_RATE) {
                continue;
            }

            $weights = [];
            for ($cursor = $a['t']; $cursor < $b['t']; $cursor = $step) {
                $step = min($b['t'], $cursor + 3600 - ($cursor % 3600));
                $bucket = $this->bucket($cursor);
                $weights[] = [$bucket, $step - $cursor, self::PRIORS[$bucket] * ($step - $cursor)];
            }

            $sum = array_sum(array_column($weights, 2)) ?: 1;
            foreach ($weights as [$bucket, $duration, $weight]) {
                $this->learned[$bucket]['hours'] = ($this->learned[$bucket]['hours'] ?? 0) + $duration / 3600;
                $this->learned[$bucket]['used'] = ($this->learned[$bucket]['used'] ?? 0) + $delta * $weight / $sum;
            }
        }
    }

    private function recentRate(Collection $samples, int $now): ?float
    {
        $recent = $samples->filter(fn (array $s) => $s['t'] >= $now - self::MOMENTUM_SECONDS)->values();

        if ($recent->count() < 2) {
            return null;
        }

        $seconds = $recent->last()['t'] - $recent->first()['t'];
        $delta = $recent->last()['used'] - $recent->first()['used'];

        if ($seconds < 300 || $delta <= 0) {
            return null;
        }

        return min($delta / $seconds, self::MAX_RATE / 3600);
    }

    private function integrate(int $from, int $to): float
    {
        $total = 0.0;
        for ($cursor = $from; $cursor < $to; $cursor = $step) {
            $step = min($to, $cursor + 3600 - ($cursor % 3600));
            $total += $this->expected($this->bucket($cursor)) * ($step - $cursor) / 3600;
        }

        return $total;
    }
}
