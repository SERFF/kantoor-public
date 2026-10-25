<?php

namespace App\Office;

use App\Models\OfficeDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class Stats
{
    public const FIELDS = ['sessions', 'prompts', 'edits', 'lines_added', 'lines_removed', 'approvals'];

    public function bump(?int $projectId, array $counts): void
    {
        $counts = array_filter(
            array_map('intval', array_intersect_key($counts, array_flip(self::FIELDS))),
            fn (int $count) => $count > 0,
        );

        if (! $counts) {
            return;
        }

        try {
            $day = OfficeDay::query()->firstOrCreate([
                'date' => CarbonImmutable::now(config('kantoor.timezone'))->toDateString(),
                'project_id' => $projectId ?: 0,
            ]);

            $changes = ['updated_at' => now()];

            foreach ($counts as $field => $count) {
                $changes[$field] = DB::raw($field.' + '.$count);
            }

            OfficeDay::query()->whereKey($day->id)->update($changes);
        } catch (Throwable $e) {
            Log::warning('Kantoor statistics failed: '.$e->getMessage());
        }
    }
}
