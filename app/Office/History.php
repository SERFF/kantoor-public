<?php

namespace App\Office;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\AgentQuestion;
use App\Models\LogEntry;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class History
{
    public const PER_PAGE = 60;

    public function __construct(protected Logbook $logbook) {}

    public function forAgent(Agent $agent, ?CarbonImmutable $before = null): array
    {
        $agent->loadMissing('project');

        return [
            'subject' => [
                'type' => 'agent',
                'id' => $agent->id,
                'name' => $agent->name,
                'avatar' => $agent->avatar,
                'subtitle' => collect([$agent->role, $agent->project?->name])->filter()->implode(' · '),
                'project_id' => $agent->project_id,
            ],
            ...$this->build(collect([$agent->id]), $before, LogEntry::query()->where('agent_id', $agent->id)),
        ];
    }

    public function forProject(?Project $project, ?CarbonImmutable $before = null): array
    {
        $ids = Agent::query()->where('project_id', $project?->id)->pluck('id');

        return [
            'subject' => [
                'type' => 'project',
                'id' => $project?->id,
                'name' => $project?->name ?? 'No project',
                'subtitle' => 'Everything that happened in this project',
            ],
            ...$this->build($ids, $before, LogEntry::query()->where('project_id', $project?->id)),
        ];
    }

    public function questions(Collection $ids): array
    {
        $questions = AgentQuestion::query()
            ->whereIn('agent_id', $ids)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $minutes = $questions
            ->filter(fn (AgentQuestion $question) => in_array($question->outcome(), ['answered', 'in_chat'], true))
            ->map(fn (AgentQuestion $question) => max(0, $question->created_at->diffInSeconds($question->answered_at)) / 60)
            ->sort()
            ->values();

        return [
            'asked' => $questions->count(),
            'answered' => $questions->filter(fn (AgentQuestion $question) => $question->outcome() === 'answered')->count(),
            'in_chat' => $questions->filter(fn (AgentQuestion $question) => $question->outcome() === 'in_chat')->count(),
            'cancelled' => $questions->filter(fn (AgentQuestion $question) => $question->outcome() === 'cancelled')->count(),
            'open' => $questions->filter(fn (AgentQuestion $question) => $question->outcome() === 'open')->count(),
            'median_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->median()),
        ];
    }

    protected function build(Collection $ids, ?CarbonImmutable $before, Builder $delivered): array
    {
        $operator = $before ? '<=' : '<';
        $before ??= CarbonImmutable::now()->addSecond();
        $limit = self::PER_PAGE + 1;

        $rawEvents = AgentEvent::query()
            ->with(['agent', 'toAgent'])
            ->where('type', '!=', 'answer')
            ->where(fn ($query) => $query->whereIn('agent_id', $ids)->orWhereIn('to_agent_id', $ids))
            ->where('created_at', $operator, $before->utc())
            ->latest('created_at')->latest('id')
            ->limit($limit)
            ->get();

        $rawQuestions = AgentQuestion::query()
            ->with('agent')
            ->whereIn('agent_id', $ids)
            ->where('created_at', $operator, $before->utc())
            ->latest('created_at')->latest('id')
            ->limit($limit)
            ->get();

        $boundary = collect([$rawEvents, $rawQuestions])
            ->filter(fn (Collection $rows) => $rows->count() > self::PER_PAGE)
            ->map(fn (Collection $rows) => $rows->last()->created_at->getTimestamp())
            ->max();

        $events = $rawEvents->map(fn (AgentEvent $event) => [
            'key' => 'e'.$event->id,
            'type' => $event->type,
            'status' => $event->status,
            'agent' => $this->agent($event->agent),
            'text' => $event->describe(),
            'message' => $event->message,
            'at' => $event->created_at,
        ]);

        $questions = $rawQuestions->map(fn (AgentQuestion $question) => [
            'key' => 'q'.$question->id,
            'type' => 'question',
            'status' => $question->outcome(),
            'agent' => $this->agent($question->agent),
            'text' => $question->question,
            'answer' => $question->outcome() === 'answered' ? $question->answer : null,
            'at' => $question->created_at,
        ]);

        $asked = $questions->map(fn (array $item) => ($item['agent']['id'] ?? 0).'|'.Str::limit($item['text'], 160))->flip();

        $items = $events
            ->reject(fn (array $item) => $item['status'] === 'waiting' && $item['agent'] && isset($asked[$item['agent']['id'].'|'.$item['message']]))
            ->concat($questions)
            ->filter(fn (array $item) => $boundary === null || $item['at']->getTimestamp() >= $boundary)
            ->sortByDesc(fn (array $item) => [$item['at']->getTimestamp(), $item['type'] === 'question' ? 1 : 0])
            ->map(fn (array $item) => collect($item)->except('message')->all())
            ->values();

        $more = $boundary !== null || $items->count() > self::PER_PAGE;
        $items = $items->take(self::PER_PAGE);
        $tz = $this->logbook->timezone();

        return [
            'items' => $items->map(fn (array $item) => [
                ...$item,
                'day' => $item['at']->setTimezone($tz)->toDateString(),
                'day_label' => ucfirst($item['at']->setTimezone($tz)->locale('en')->isoFormat('dddd, MMMM D')),
                'time' => $item['at']->setTimezone($tz)->format('H:i'),
                'at' => $item['at']->toIso8601String(),
            ])->values(),
            'next' => $more ? ($items->last()['at'] ?? CarbonImmutable::createFromTimestamp($boundary))->toIso8601String() : null,
            'delivered' => $delivered->where('created_at', '>=', now()->subDays(30))->count(),
            'keep_days' => (int) config('kantoor.keep_events_days'),
            'questions' => $operator === '<' ? $this->questions($ids) : null,
        ];
    }

    protected function agent(?Agent $agent): ?array
    {
        return $agent ? ['id' => $agent->id, 'name' => $agent->name, 'avatar' => $agent->avatar] : null;
    }
}
