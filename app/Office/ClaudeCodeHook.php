<?php

namespace App\Office;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClaudeCodeHook
{
    public const EDIT_TOOLS = ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'];

    public ?string $request = null;

    public function __construct(protected Office $office, protected HookRequests $requests, protected Stats $stats) {}

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
        $wait = (bool) ($payload['wait'] ?? false);

        match ($event) {
            'SessionStart' => $this->start($agent, (string) ($payload['source'] ?? 'startup')),
            'UserPromptSubmit' => $this->prompt($agent),
            'PreToolUse' => match (true) {
                $this->isDelegation($tool) => $this->brief($agent, $session, $input),
                $tool === 'AskUserQuestion' => $this->question($this->subagent($agent, $session, $payload) ?? $agent, $input, $wait),
                default => $this->office->activity($this->subagent($agent, $session, $payload) ?? $agent, $this->describe($tool, $input)),
            },
            'PermissionRequest' => $this->permission($this->subagent($agent, $session, $payload) ?? $agent, $payload, $tool, $input, $wait),
            'PostToolUse' => in_array($tool, self::EDIT_TOOLS, true)
                ? $this->edited($this->subagent($agent, $session, $payload) ?? $agent, $tool, $input, $payload)
                : $agent->forceFill(['last_seen_at' => now()])->save(),
            'SubagentStart' => $this->delegate($agent, $session, $payload),
            'SubagentStop' => $this->delegated($agent, $session, $payload),
            'Notification' => $this->office->report($agent, Status::Waiting, $this->notification((string) ($payload['message'] ?? ''))),
            'Stop' => $this->stop($agent, $session, Status::Done, 'Finished the task'),
            'SessionEnd' => $this->stop($agent, $session, Status::Offline, null),
            'PreCompact' => $this->office->activity($agent, 'Tidying up its memory'),
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
            $server = explode('__', $tool)[1] ?? 'a connector';

            return 'Using '.str_replace(['_', '-'], ' ', $server);
        }

        return match ($tool) {
            'Read' => $file ? "Reading {$file}" : 'Reading a file',
            'Edit', 'MultiEdit', 'NotebookEdit' => $file ? "Editing {$file}" : 'Editing a file',
            'Write' => $file ? "Writing {$file}" : 'Writing a file',
            'Bash' => isset($input['description']) ? Str::limit((string) $input['description'], 80) : 'Running a command',
            'Grep', 'Glob' => 'Searching the code',
            'WebFetch' => 'Reading '.(parse_url((string) ($input['url'] ?? ''), PHP_URL_HOST) ?: 'a web page'),
            'WebSearch' => 'Searching the web',
            'TodoWrite', 'TaskCreate', 'TaskUpdate' => 'Updating the task list',
            default => $tool !== '' ? "Using {$tool}" : 'Working',
        };
    }

    protected function start(Agent $agent, string $source): void
    {
        if ($source === 'startup') {
            $this->stats->bump($agent->project_id, ['sessions' => 1]);
        }

        if ($agent->status !== Status::Working) {
            $this->office->report($agent, Status::Idle, 'Ready for a task');
        }
    }

    protected function prompt(Agent $agent): void
    {
        $this->stats->bump($agent->project_id, ['prompts' => 1]);
        $this->office->report($agent, Status::Working, 'New task');
    }

    protected function question(Agent $agent, array $input, bool $wait): void
    {
        $asked = $wait ? $this->requests->questions($agent, (array) ($input['questions'] ?? [])) : null;

        if ($asked) {
            $this->request = $asked->request;

            return;
        }

        $first = $input['questions'][0]['question'] ?? null;
        $this->office->report($agent, Status::Waiting, $first ? 'Question in the terminal: '.$first : 'Has a question for you in the terminal');
    }

    protected function permission(Agent $agent, array $payload, string $tool, array $input, bool $wait): void
    {
        $what = $this->describe($tool, $input);

        if (! $wait) {
            $this->office->report($agent, Status::Waiting, 'Waiting for permission: '.$what);

            return;
        }

        $suggestions = is_array($payload['permission_suggestions'] ?? null) ? $payload['permission_suggestions'] : [];
        $this->request = $this->requests->permission($agent, $what, $this->detail($tool, $input, $payload['cwd'] ?? null), $suggestions)->request;
    }

    public function detail(string $tool, array $input, ?string $cwd): ?string
    {
        if (isset($input['command'])) {
            return (string) $input['command'];
        }

        if (isset($input['file_path'])) {
            $path = str_replace('\\', '/', (string) $input['file_path']);
            $root = rtrim(str_replace('\\', '/', (string) $cwd), '/');

            return $root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : basename($path);
        }

        if (isset($input['url'])) {
            return (string) $input['url'];
        }

        return str_starts_with($tool, 'mcp__') ? $tool : null;
    }

    protected function edited(Agent $agent, string $tool, array $input, array $payload): void
    {
        [$added, $removed] = $this->lines($tool, $input, $payload);

        $this->stats->bump($agent->project_id, ['edits' => 1, 'lines_added' => $added, 'lines_removed' => $removed]);

        $counts = $added || $removed ? sprintf(' +%d −%d', $added, $removed) : '';
        $this->office->activity($agent, $this->describe($tool, $input).$counts);
    }

    public function lines(string $tool, array $input, array $payload): array
    {
        if (is_array($payload['lines'] ?? null)) {
            return [max(0, (int) ($payload['lines']['added'] ?? 0)), max(0, (int) ($payload['lines']['removed'] ?? 0))];
        }

        $count = fn ($text) => is_string($text) && $text !== '' ? substr_count(rtrim($text, "\n"), "\n") + 1 : 0;

        return match ($tool) {
            'Write' => [$count($input['content'] ?? null), 0],
            'Edit' => [$count($input['new_string'] ?? null), $count($input['old_string'] ?? null)],
            'MultiEdit' => array_reduce((array) ($input['edits'] ?? []), fn ($sum, $edit) => [
                $sum[0] + $count($edit['new_string'] ?? null),
                $sum[1] + $count($edit['old_string'] ?? null),
            ], [0, 0]),
            default => [0, 0],
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

        $this->office->activity($parent, 'Starting a subagent');
    }

    protected function delegate(Agent $parent, string $session, array $payload): void
    {
        $sub = $this->subagent($parent, $session, $payload);

        if (! $sub) {
            return;
        }

        $key = $this->briefingKey($session, (string) ($payload['agent_type'] ?? ''));
        $briefings = Cache::get($key, []);
        $what = array_shift($briefings) ?: 'a task';
        Cache::put($key, $briefings, now()->addHour());

        $this->office->handoff($parent, $sub, $what);
        $this->office->report($sub, Status::Working, $what);
        $this->office->activity($parent, 'Waiting for '.$sub->name);
    }

    protected function delegated(Agent $parent, string $session, array $payload): void
    {
        $sub = $this->subagent($parent, $session, $payload);

        if (! $sub) {
            return;
        }

        $this->office->report($sub, Status::Done, 'Returned the result');
        $this->office->handoff($sub, $parent, 'result');

        if ($parent->status === Status::Working) {
            $this->office->activity($parent, 'Processing the work from '.$sub->name);
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
            str_contains($lower, 'permission') => 'Waiting for permission',
            str_contains($lower, 'waiting for your input'), str_contains($lower, 'idle') => 'Waiting for your reply',
            $message === '' => 'Needs you',
            default => Str::limit($message, 100),
        };
    }
}
