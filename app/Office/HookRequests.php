<?php

namespace App\Office;

use App\Models\Agent;
use App\Models\AgentQuestion;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HookRequests
{
    public const ALLOW = 'Allow';

    public const ALWAYS = 'Always allow';

    public const DENY = 'Deny';

    public const TERMINAL = 'Answer in the terminal';

    public function __construct(protected Office $office, protected Stats $stats) {}

    public function permission(Agent $agent, string $what, ?string $detail = null, array $suggestions = []): AgentQuestion
    {
        $always = $this->allowRules($suggestions);

        return $this->office->ask(
            $agent,
            'Permission for: '.$what,
            array_values(array_filter([self::ALLOW, $always ? self::ALWAYS : null, self::DENY, self::TERMINAL])),
            'Waiting for permission: '.$what,
            ['kind' => 'permission', 'request' => (string) Str::uuid(), 'payload' => [
                'detail' => $this->office->clean($detail, 300),
                'always' => $always,
            ]],
        );
    }

    public function questions(Agent $agent, array $questions): ?AgentQuestion
    {
        $questions = collect($questions)
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['question'] ?? '')) !== '')
            ->take(4)
            ->values()
            ->all();

        if (! $questions) {
            return null;
        }

        return $this->askQuestion($agent, (string) Str::uuid(), $questions, 0, []);
    }

    protected function askQuestion(Agent $agent, string $request, array $questions, int $index, array $answers): AgentQuestion
    {
        $item = $questions[$index];
        $labels = collect($item['options'] ?? [])
            ->map(fn ($option) => trim(is_array($option) ? (string) ($option['label'] ?? '') : (string) $option))
            ->filter()
            ->unique()
            ->take(4)
            ->values()
            ->all();
        $count = count($questions);
        $text = (string) $item['question'];

        return $this->office->ask(
            $agent,
            $count > 1 ? sprintf('(%d/%d) %s', $index + 1, $count, $text) : $text,
            [...$labels, self::TERMINAL],
            null,
            ['kind' => 'ask', 'request' => $request, 'payload' => [
                'questions' => $questions,
                'index' => $index,
                'answers' => $answers,
                'labels' => $labels,
                'multi' => (bool) ($item['multiSelect'] ?? false),
            ]],
        );
    }

    public function answer(AgentQuestion $question, string $answer): void
    {
        $agent = $question->agent;
        $answer = trim($answer);
        $payload = $question->payload ?? [];

        $question->forceFill([
            'answer' => Str::limit($answer, 490),
            'answered_at' => now(),
            'collected_at' => now(),
        ])->save();

        if ($answer === self::TERMINAL) {
            $this->finish($question, null);
            $this->office->report($agent, Status::Waiting, 'Waiting for your answer in the terminal');

            return;
        }

        if ($question->kind === 'permission') {
            $decision = match ($answer) {
                self::ALLOW => ['behavior' => 'allow'],
                self::ALWAYS => ['behavior' => 'allow', 'updatedPermissions' => $payload['always'] ?? []],
                self::DENY => ['behavior' => 'deny', 'message' => 'The user denied this in the office.'],
                default => ['behavior' => 'deny', 'message' => 'The user denied this in the office and says: '.$answer],
            };

            $this->finish($question, ['hookSpecificOutput' => ['hookEventName' => 'PermissionRequest', 'decision' => $decision]]);
            $this->stats->bump($agent->project_id, ['approvals' => 1]);
            $this->office->report($agent, Status::Working, $decision['behavior'] === 'allow' ? 'Allowed to carry on' : 'Looking for another way');

            return;
        }

        $questions = $payload['questions'] ?? [];
        $index = (int) ($payload['index'] ?? 0);
        $answers = $payload['answers'] ?? [];
        $answers[(string) ($questions[$index]['question'] ?? '')] = $this->label($question, $answer);

        if ($index + 1 < count($questions)) {
            $this->finish($question, null, 'next');
            $this->askQuestion($agent, (string) $question->request, $questions, $index + 1, $answers);

            return;
        }

        $this->finish($question, ['hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'allow',
            'updatedInput' => ['questions' => $questions, 'answers' => $answers],
        ]]);
        $this->stats->bump($agent->project_id, ['approvals' => 1]);
        $this->office->report($agent, Status::Working, 'Carrying on with your answer');
    }

    public function status(string $request): array
    {
        $question = AgentQuestion::query()->where('request', $request)->latest('id')->first();
        $state = $question?->payload['state'] ?? null;

        if ($state === 'answered') {
            $output = $question->payload['output'] ?? null;
            $question->forceFill(['payload' => ['state' => 'delivered']])->save();

            return ['status' => 'answered', 'output' => $output];
        }

        return match (true) {
            ! $question, $state === 'delivered' => ['status' => 'gone'],
            $state === 'released', $question->cancelled_at !== null => ['status' => 'released'],
            default => ['status' => 'pending'],
        };
    }

    public function cancel(string $request): void
    {
        $open = AgentQuestion::query()->with('agent')->where('request', $request)->open()->get();

        if ($open->isEmpty()) {
            return;
        }

        $open->each(fn (AgentQuestion $question) => $this->finish($question->forceFill(['cancelled_at' => now()]), null));
        $this->office->report($open->first()->agent, Status::Waiting, 'Waiting for your answer in the terminal');
    }

    protected function finish(AgentQuestion $question, ?array $output, ?string $state = null): void
    {
        $question->forceFill(['payload' => [
            ...Arr::only($question->payload ?? [], ['index', 'multi']),
            'state' => $state ?? ($output ? 'answered' : 'released'),
            'output' => $output,
        ]])->save();
    }

    protected function label(AgentQuestion $question, string $answer): string
    {
        $index = array_search($answer, $question->options ?? [], true);
        $labels = $question->payload['labels'] ?? [];

        return $index !== false && isset($labels[$index]) ? $labels[$index] : $answer;
    }

    protected function allowRules(array $suggestions): array
    {
        return collect($suggestions)
            ->filter(fn ($entry) => is_array($entry) && ($entry['type'] ?? null) === 'addRules' && ($entry['behavior'] ?? null) === 'allow')
            ->take(3)
            ->values()
            ->all();
    }
}
