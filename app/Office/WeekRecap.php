<?php

namespace App\Office;

use App\Models\LogEntry;
use App\Models\OfficeDay;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class WeekRecap
{
    public function __construct(protected Logbook $logbook) {}

    public function start(?string $date = null): CarbonImmutable
    {
        return $this->logbook->day($date)->startOfWeek(CarbonInterface::MONDAY);
    }

    public function current(): array
    {
        $now = CarbonImmutable::now($this->logbook->timezone());
        $previous = $now->isMonday() && $now->hour < 12;

        return $this->week($previous ? $now->subWeek()->toDateString() : null) + ['previous' => $previous];
    }

    public function week(?string $date = null): array
    {
        $start = $this->start($date);
        $until = $start->addWeek();
        $entries = $this->logbook->entries($start, $until);
        $days = OfficeDay::query()
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $until->toDateString())
            ->get();
        $names = Project::query()->pluck('name', 'id');

        $projects = $this->projects($entries, $days, $names);
        $busiest = collect(range(0, 6))
            ->map(function (int $offset) use ($start, $entries, $days) {
                $day = $start->addDays($offset);
                $done = $entries->filter(fn (LogEntry $entry) => $entry->created_at->setTimezone($this->logbook->timezone())->isSameDay($day))->count();
                $prompts = (int) $days->filter(fn (OfficeDay $row) => substr((string) $row->date, 0, 10) === $day->toDateString())->sum('prompts');

                return ['day' => $day->locale('en')->isoFormat('dddd'), 'date' => $day->toDateString(), 'done' => $done, 'prompts' => $prompts];
            })
            ->filter(fn (array $day) => $day['done'] || $day['prompts'])
            ->sortByDesc(fn (array $day) => [$day['done'], $day['prompts']])
            ->first();

        $star = $entries->groupBy('agent_name')->sortByDesc(fn (Collection $group) => $group->count())->first();

        return [
            'from' => $start->toDateString(),
            'until' => $until->subDay()->toDateString(),
            'week' => $start->isoWeek(),
            'done' => $entries->count(),
            'sessions' => (int) $days->sum('sessions'),
            'prompts' => (int) $days->sum('prompts'),
            'edits' => (int) $days->sum('edits'),
            'lines_added' => (int) $days->sum('lines_added'),
            'lines_removed' => (int) $days->sum('lines_removed'),
            'approvals' => (int) $days->sum('approvals'),
            'busiest' => $busiest,
            'star' => $star && $star->count() >= 2 ? ['name' => $star->first()->agent_name, 'avatar' => (int) $star->first()->avatar, 'count' => $star->count()] : null,
            'projects' => $projects->take(5)->values()->all(),
        ];
    }

    protected function projects(Collection $entries, Collection $days, Collection $names): Collection
    {
        $ids = $entries->pluck('project_id')->map(fn ($id) => (int) $id)
            ->merge($days->pluck('project_id')->map(fn ($id) => (int) $id))
            ->unique();

        return $ids
            ->map(fn (int $id) => [
                'project_id' => $id ?: null,
                'name' => $names[$id] ?? 'No project',
                'done' => $entries->filter(fn (LogEntry $entry) => (int) $entry->project_id === $id)->count(),
                'prompts' => (int) $days->where('project_id', $id)->sum('prompts'),
                'edits' => (int) $days->where('project_id', $id)->sum('edits'),
            ])
            ->filter(fn (array $project) => $project['done'] || $project['prompts'] || $project['edits'])
            ->sortByDesc(fn (array $project) => [$project['done'], $project['prompts'], $project['edits']])
            ->values();
    }

    public function summary(?string $date = null): string
    {
        $week = $this->week($date);
        $from = CarbonImmutable::parse($week['from'])->locale('en')->isoFormat('MMMM D');
        $until = CarbonImmutable::parse($week['until'])->locale('en')->isoFormat('MMMM D');

        $lines = ["Week {$week['week']} ({$from} to {$until})."];
        $lines[] = sprintf('Delivered: %d. Claude Code sessions: %d, prompts: %d.', $week['done'], $week['sessions'], $week['prompts']);
        $lines[] = sprintf('Files edited: %d (+%d −%d lines). Approvals and answers in the office: %d.', $week['edits'], $week['lines_added'], $week['lines_removed'], $week['approvals']);

        if ($week['busiest']) {
            $lines[] = sprintf('Busiest day: %s (%d delivered, %d prompts).', $week['busiest']['day'], $week['busiest']['done'], $week['busiest']['prompts']);
        }

        if ($week['star']) {
            $lines[] = sprintf('Most deliveries: %s (%d).', $week['star']['name'], $week['star']['count']);
        }

        if ($week['projects']) {
            $lines[] = '';
            $lines[] = 'Per project:';

            foreach ($week['projects'] as $project) {
                $lines[] = sprintf('- %s: %d delivered, %d prompts, %d edits', $project['name'], $project['done'], $project['prompts'], $project['edits']);
            }
        }

        return implode("\n", $lines);
    }
}
