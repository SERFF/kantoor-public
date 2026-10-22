<?php

namespace App\Office;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\LogEntry;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class Logbook
{
    public function record(Agent $agent, AgentEvent $event): void
    {
        LogEntry::query()->create([
            'project_id' => $agent->project_id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'agent_role' => $agent->role,
            'avatar' => $agent->avatar,
            'message' => $event->message,
            'created_at' => $event->created_at ?? now(),
        ]);
    }

    public function timezone(): string
    {
        return config('kantoor.timezone');
    }

    public function day(?string $date = null): CarbonImmutable
    {
        $tz = $this->timezone();

        return ($date ? CarbonImmutable::parse($date, $tz) : CarbonImmutable::now($tz))->startOfDay();
    }

    public function entries(CarbonImmutable $from, CarbonImmutable $until, ?Project $project = null): Collection
    {
        return LogEntry::query()
            ->with('project')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $until->utc())
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function today(): array
    {
        $day = $this->day();
        $entries = $this->entries($day, $day->addDay());

        return [
            'date' => $day->toDateString(),
            'done' => $entries->count(),
            'projects' => $entries
                ->groupBy(fn (LogEntry $entry) => $entry->project_id ?? 0)
                ->map(fn (Collection $group) => [
                    'project_id' => $group->first()->project_id,
                    'name' => $group->first()->project?->name ?? 'No project',
                    'count' => $group->count(),
                    'items' => $group->take(5)->map(fn (LogEntry $entry) => [
                        'agent' => $entry->agent_name,
                        'message' => $entry->message,
                        'time' => $entry->created_at->setTimezone($this->timezone())->format('H:i'),
                    ])->values(),
                ])
                ->sortByDesc('count')
                ->values(),
        ];
    }

    public function star(): ?array
    {
        $since = $this->day()->subDays(6);

        $top = LogEntry::query()
            ->where('created_at', '>=', $since->utc())
            ->selectRaw('agent_name, max(avatar) as avatar, max(agent_role) as role, max(project_id) as project_id, count(*) as total')
            ->groupBy('agent_name')
            ->orderByDesc('total')
            ->orderBy('agent_name')
            ->first();

        if (! $top || $top->total < 2) {
            return null;
        }

        return [
            'name' => $top->agent_name,
            'avatar' => (int) $top->avatar,
            'role' => $top->role,
            'project_id' => $top->project_id,
            'count' => (int) $top->total,
        ];
    }

    public function summary(?string $date = null, ?Project $project = null): string
    {
        $day = $this->day($date);
        $entries = $this->entries($day, $day->addDay(), $project);
        $label = $day->locale('en')->isoFormat('dddd, MMMM D');

        $lines = [];

        if ($entries->isEmpty()) {
            $lines[] = 'Nothing was delivered'.($project ? " for {$project->name}" : '')." on {$label}.";
        } else {
            $lines[] = "Delivered on {$label}: {$entries->count()}.";

            foreach ($entries->groupBy(fn (LogEntry $entry) => $entry->project?->name ?? 'No project') as $name => $group) {
                $lines[] = '';
                $lines[] = "{$name} ({$group->count()})";

                foreach ($group->sortBy('created_at') as $entry) {
                    $lines[] = sprintf('- %s %s: %s', $entry->created_at->setTimezone($this->timezone())->format('H:i'), $entry->agent_name, $entry->message ?: 'done');
                }
            }
        }

        if (! $date || $day->isToday()) {
            $open = Agent::query()
                ->with('project')
                ->whereIn('status', [Status::Waiting->value, Status::Error->value, Status::Working->value])
                ->when($project, fn ($query) => $query->where('project_id', $project->id))
                ->orderBy('status')
                ->get();

            if ($open->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Still open:';

                foreach ($open as $agent) {
                    $lines[] = sprintf('- %s (%s): %s%s', $agent->name, $agent->project?->name ?? 'no project', $agent->status->label(), $agent->message ? ' – '.$agent->message : '');
                }
            }
        }

        return implode("\n", $lines);
    }
}
