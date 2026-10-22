<?php

namespace App\Office;

use App\Events\AlarmsChanged;
use App\Models\Alarm;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Switchboard
{
    public const ACTIONS = ['new_issue', 'regression', 'frequency', 'test'];

    public function alert(array $payload): Alarm
    {
        $projectName = Str::limit(trim((string) data_get($payload, 'project.name', 'Monitoring')), 80, '');
        $action = (string) data_get($payload, 'action', 'new_issue');
        $issueKey = (string) (data_get($payload, 'issue.id') ?? data_get($payload, 'issue.short_id', ''));

        if ($action === 'test') {
            $issueKey = 'test-'.Str::slug($projectName);
        }

        $alarm = Alarm::query()->firstOrNew(['source' => 'webhook', 'issue_key' => Str::limit($issueKey, 80, '')]);
        $wasRinging = $alarm->exists && $alarm->isRinging();

        $alarm->fill([
            'project_id' => $this->project((string) data_get($payload, 'project.slug'), $projectName)?->id,
            'project_name' => $projectName,
            'short_id' => Str::limit((string) data_get($payload, 'issue.short_id'), 40, '') ?: null,
            'level' => Str::limit((string) data_get($payload, 'issue.level'), 20, '') ?: null,
            'action' => $action,
            'times_seen' => max(1, (int) data_get($payload, 'issue.times_seen', 1)),
            'url' => $this->url(data_get($payload, 'issue.url')),
        ]);

        if (! $wasRinging) {
            $alarm->rang_at = now();
            $alarm->answered_at = null;
        }

        $alarm->save();

        $this->broadcast($wasRinging ? null : $alarm->id);

        return $alarm;
    }

    public function answer(?Alarm $alarm = null): void
    {
        Alarm::query()
            ->ringing()
            ->when($alarm, fn ($query) => $query->whereKey($alarm->id))
            ->update(['answered_at' => now()]);

        $this->broadcast();
    }

    public function broadcast(?int $ringing = null): void
    {
        try {
            broadcast(new AlarmsChanged($ringing));
        } catch (Throwable $e) {
            Log::warning('Kantoor alarm broadcast failed: '.$e->getMessage());
        }
    }

    protected function project(string $slug, string $name): ?Project
    {
        foreach (array_unique(array_filter([Str::slug($slug), Str::slug($name)])) as $candidate) {
            $project = Project::query()->firstWhere('slug', $candidate)
                ?? Project::query()->whereNotNull('match')->get()->first(fn (Project $project) => $project->matches($candidate));

            if ($project) {
                return $project;
            }
        }

        return null;
    }

    protected function url(mixed $url): ?string
    {
        $url = (string) $url;

        return $url !== '' && preg_match('#^https://#i', $url) && strlen($url) <= 500 ? $url : null;
    }
}
