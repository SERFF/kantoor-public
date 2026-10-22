<?php

namespace App\Office;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClaudeCodeHook
{
    public function __construct(protected Office $office) {}

    public function handle(array $payload): ?Agent
    {
        $session = (string) ($payload['session_id'] ?? '');
        $event = (string) ($payload['hook_event_name'] ?? '');

        if ($session === '' || $event === '') {
            return null;
        }

        $agent = $this->office->agent('cc:'.$session, [
            'source' => 'claude_code',
            'role' => 'Claude Code',
            'project' => $this->project($payload['cwd'] ?? null),
        ]);

        $tool = (string) ($payload['tool_name'] ?? '');
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];

        match ($event) {
            'SessionStart' => $this->office->report($agent, Status::Idle, 'Zit klaar voor een opdracht'),
            'UserPromptSubmit' => $this->office->report($agent, Status::Working, 'Nieuwe opdracht'),
            'PreToolUse' => $this->isDelegation($tool)
                ? $this->brief($agent, $session, $input)
                : $this->office->activity($this->subagent($agent, $session, $payload) ?? $agent, $this->describe($tool, $input)),
            'SubagentStart' => $this->delegate($agent, $session, $payload),
            'SubagentStop' => $this->delegated($agent, $session, $payload),
            'Notification' => $this->office->report($agent, Status::Waiting, $this->notification((string) ($payload['message'] ?? ''))),
            'Stop' => $this->stop($agent, $session, Status::Done, 'Klaar met de opdracht'),
            'SessionEnd' => $this->stop($agent, $session, Status::Offline, null),
            'PreCompact' => $this->office->activity($agent, 'Ruimt het geheugen op'),
            default => $agent->forceFill(['last_seen_at' => now()])->save(),
        };

        return $agent->fresh();
    }

    public function project(?string $cwd): ?string
    {
        $cwd = rtrim(str_replace('\\', '/', (string) $cwd), '/');

        if ($cwd === '') {
            return null;
        }

        foreach (config('kantoor.code_roots', []) as $root) {
            if (preg_match('#/'.preg_quote($root, '#').'/([^/]+)#i', $cwd, $match)) {
                return $match[1];
            }
        }

        return basename($cwd);
    }

    public function describe(string $tool, array $input): string
    {
        $file = isset($input['file_path']) ? basename((string) $input['file_path']) : null;

        if (str_starts_with($tool, 'mcp__')) {
            $server = explode('__', $tool)[1] ?? 'een connector';

            return 'Gebruikt '.str_replace(['_', '-'], ' ', $server);
        }

        return match ($tool) {
            'Read' => $file ? "Leest {$file}" : 'Leest een bestand',
            'Edit', 'MultiEdit', 'NotebookEdit' => $file ? "Bewerkt {$file}" : 'Bewerkt een bestand',
            'Write' => $file ? "Schrijft {$file}" : 'Schrijft een bestand',
            'Bash' => isset($input['description']) ? Str::limit((string) $input['description'], 80) : 'Draait een commando',
            'Grep', 'Glob' => 'Zoekt in de code',
            'WebFetch' => 'Leest '.(parse_url((string) ($input['url'] ?? ''), PHP_URL_HOST) ?: 'een webpagina'),
            'WebSearch' => 'Zoekt online',
            'TodoWrite', 'TaskCreate', 'TaskUpdate' => 'Werkt de takenlijst bij',
            default => $tool !== '' ? "Gebruikt {$tool}" : 'Aan het werk',
        };
    }

    protected function isDelegation(string $tool): bool
    {
        return in_array($tool, ['Task', 'Agent'], true);
    }

    protected function subagent(Agent $parent, string $session, array $payload): ?Agent
    {
        $id = (string) ($payload['agent_id'] ?? '');

        if ($id === '') {
            return null;
        }

        $type = (string) ($payload['agent_type'] ?? '') ?: 'general-purpose';

        return $this->office->agent('cc:'.$session.':'.Str::slug($id), [
            'source' => 'subagent',
            'role' => 'Subagent · '.$type,
            'project' => $parent->project?->name,
            'parent_id' => $parent->id,
        ]);
    }

    protected function brief(Agent $parent, string $session, array $input): void
    {
        $key = $this->briefingKey($session, (string) ($input['subagent_type'] ?? ''));
        $briefings = Cache::get($key, []);
        $briefings[] = Str::limit((string) ($input['description'] ?? ''), 60, '');

        Cache::put($key, array_slice($briefings, -20), now()->addHour());

        $this->office->activity($parent, 'Zet een subagent aan het werk');
    }

    protected function delegate(Agent $parent, string $session, array $payload): void
    {
        $sub = $this->subagent($parent, $session, $payload);

        if (! $sub) {
            return;
        }

        $key = $this->briefingKey($session, (string) ($payload['agent_type'] ?? ''));
        $briefings = Cache::get($key, []);
        $what = array_shift($briefings) ?: 'een taak';
        Cache::put($key, $briefings, now()->addHour());

        $this->office->handoff($parent, $sub, $what);
        $this->office->report($sub, Status::Working, $what);
        $this->office->activity($parent, 'Wacht op '.$sub->name);
    }

    protected function delegated(Agent $parent, string $session, array $payload): void
    {
        $sub = $this->subagent($parent, $session, $payload);

        if (! $sub) {
            return;
        }

        $this->office->report($sub, Status::Done, 'Resultaat teruggegeven');
        $this->office->handoff($sub, $parent, 'resultaat');

        if ($parent->status === Status::Working) {
            $this->office->activity($parent, 'Verwerkt het werk van '.$sub->name);
        }
    }

    protected function briefingKey(string $session, string $type): string
    {
        return 'kantoor:briefings:'.$session.':'.Str::slug($type ?: 'general-purpose');
    }

    protected function stop(Agent $agent, string $session, Status $status, ?string $message): void
    {
        if ($status === Status::Offline) {
            Agent::query()
                ->where('parent_id', $agent->id)
                ->where('key', 'like', 'cc:'.$session.':%')
                ->where('status', '!=', Status::Offline->value)
                ->get()
                ->each(fn (Agent $sub) => $this->office->report($sub, Status::Offline));
        }

        $this->office->report($agent, $status, $message);
    }

    protected function notification(string $message): string
    {
        $lower = Str::lower($message);

        return match (true) {
            str_contains($lower, 'permission') => 'Wacht op toestemming',
            str_contains($lower, 'waiting for your input'), str_contains($lower, 'idle') => 'Wacht op je antwoord',
            $message === '' => 'Heeft je nodig',
            default => Str::limit($message, 100),
        };
    }
}
