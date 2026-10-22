<?php

namespace App\Office;

use App\Events\OfficeChanged;
use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\AgentQuestion;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Office
{
    public const AVATARS = 24;

    public function __construct(protected Logbook $logbook) {}

    public function agent(string $key, array $attributes = [], array $updates = []): Agent
    {
        $agent = Agent::query()->firstWhere('key', $key);

        if ($agent) {
            $changes = array_filter([
                'name' => $updates['name'] ?? null,
                'role' => $updates['role'] ?? null,
                'project_id' => ! empty($updates['project']) ? Project::resolve($updates['project'])?->id : null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($changes) {
                $agent->fill($changes);
                $this->matchAvatar($agent);
                $agent->save();
            }

            return $agent;
        }

        $name = $attributes['name'] ?? null;
        $avatar = $this->freeAvatar($name ? $this->genderOfName($name) : null);

        $agent = Agent::query()->create([
            'key' => $key,
            'name' => $name ?? $this->freeName($this->genderOfAvatar($avatar)),
            'role' => $attributes['role'] ?? null,
            'source' => $attributes['source'] ?? 'mcp',
            'project_id' => Project::resolve($attributes['project'] ?? null)?->id,
            'parent_id' => $attributes['parent_id'] ?? null,
            'avatar' => $avatar,
            'status' => Status::Idle,
            'status_changed_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->broadcast($agent);

        return $agent;
    }

    public function adopt(Agent $child, ?Agent $parent): Agent
    {
        if (! $parent || $parent->is($child) || $child->parent_id) {
            return $child;
        }

        $child->forceFill([
            'parent_id' => $parent->id,
            'project_id' => $child->project_id ?? $parent->project_id,
        ])->save();

        return $child;
    }

    public function parentByName(string $key): ?Agent
    {
        if (! preg_match('/^(.+)-(onderzoeker|bouwer|reviewer|tester)(-\d+)?$/', $key, $match)) {
            return null;
        }

        return Agent::query()->firstWhere('key', $match[1]);
    }

    public function report(Agent $agent, Status $status, ?string $message = null): Agent
    {
        if ($status !== Status::Waiting) {
            $this->cancelQuestions($agent);
        }

        $message = $this->clean($message);
        $changed = $agent->status !== $status;

        $agent->forceFill([
            'status' => $status,
            'message' => $message ?? ($changed ? null : $agent->message),
            'last_seen_at' => now(),
            'status_changed_at' => $changed ? now() : $agent->status_changed_at,
        ])->save();

        $event = $changed || ($message !== null && $status !== Status::Working)
            ? $agent->events()->create(['type' => 'status', 'status' => $status->value, 'message' => $message])
            : null;

        if ($event && $status === Status::Done) {
            $this->logbook->record($agent, $event);
        }

        $this->broadcast($agent, $event);

        return $agent;
    }

    public function ask(Agent $agent, string $question, array $options = [], ?string $message = null): AgentQuestion
    {
        $this->cancelQuestions($agent);
        $agent->questions()->uncollected()->update(['cancelled_at' => now()]);

        $options = collect($options)
            ->map(fn ($option) => $this->clean((string) $option, 60))
            ->filter()
            ->unique()
            ->take(6)
            ->values()
            ->all();

        $asked = $agent->questions()->create([
            'question' => $this->clean($question, 300),
            'options' => $options ?: null,
        ]);

        $agent->unsetRelation('openQuestion');

        $this->report($agent, Status::Waiting, $message ?? $question);

        return $asked;
    }

    public function answer(AgentQuestion $question, string $answer): AgentQuestion
    {
        $question->forceFill([
            'answer' => $this->clean($answer, 500),
            'answered_at' => now(),
        ])->save();

        $agent = $question->agent;

        $event = $agent->events()->create(['type' => 'answer', 'message' => 'Antwoord gegeven op de vraag']);

        $agent->forceFill(['status' => Status::Idle, 'message' => 'Antwoord staat klaar', 'status_changed_at' => now()])->save();

        $this->broadcast($agent, $event);

        return $question;
    }

    public function collect(Agent $agent): ?AgentQuestion
    {
        $question = $agent->questions()->uncollected()->latest('answered_at')->first();

        if (! $question) {
            return null;
        }

        $question->forceFill(['collected_at' => now()])->save();

        $this->report($agent, Status::Working, 'Gaat verder met je antwoord');

        return $question;
    }

    protected function cancelQuestions(Agent $agent): void
    {
        $agent->questions()->open()->update(['cancelled_at' => now()]);
        $agent->unsetRelation('openQuestion');
    }

    public function activity(Agent $agent, ?string $message): Agent
    {
        if ($agent->status !== Status::Working) {
            return $this->report($agent, Status::Working, $message);
        }

        $agent->forceFill(['message' => $this->clean($message) ?? $agent->message, 'last_seen_at' => now()])->save();

        $this->broadcast($agent);

        return $agent;
    }

    public function handoff(Agent $from, Agent $to, ?string $what = null): AgentEvent
    {
        $event = $from->events()->create([
            'type' => 'handoff',
            'to_agent_id' => $to->id,
            'message' => $this->clean($what, 60),
        ]);

        $from->forceFill(['last_seen_at' => now()])->save();

        $this->broadcast($from, $event, $to);

        return $event;
    }

    public function broadcast(Agent $agent, ?AgentEvent $event = null, ?Agent $other = null): void
    {
        try {
            broadcast(new OfficeChanged(
                agents: array_values(array_filter([$agent->fresh(['project', 'openQuestion']), $other?->fresh(['project', 'openQuestion'])])),
                event: $event?->load(['agent', 'toAgent']),
            ));
        } catch (Throwable $e) {
            Log::warning('Kantoor-broadcast mislukt: '.$e->getMessage());
        }
    }

    public function clean(?string $text, int $limit = 160): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        return $text === '' ? null : Str::limit($text, $limit);
    }

    public function matchAvatar(Agent $agent): void
    {
        $gender = $this->genderOfName((string) $agent->name);

        if ($gender && $gender !== $this->genderOfAvatar((int) $agent->avatar)) {
            $agent->avatar = $this->freeAvatar($gender, $agent->id);
        }
    }

    public function genderOfName(string $name): ?string
    {
        $first = Str::lower(Str::before(trim($name), ' '));

        foreach (config('kantoor.names', []) as $gender => $names) {
            if (in_array($first, array_map(Str::lower(...), $names), true)) {
                return $gender;
            }
        }

        return null;
    }

    public function genderOfAvatar(int $avatar): ?string
    {
        foreach (config('kantoor.avatars', []) as $gender => $avatars) {
            if (in_array($avatar, $avatars, true)) {
                return $gender;
            }
        }

        return null;
    }

    protected function freeName(?string $gender): string
    {
        $taken = Agent::query()->pluck('name')->map(fn ($name) => Str::lower($name))->all();
        $names = config('kantoor.names', []);
        $pool = $gender && ! empty($names[$gender]) ? $names[$gender] : array_merge(...array_values($names));

        foreach ($pool as $name) {
            if (! in_array(Str::lower($name), $taken, true)) {
                return $name;
            }
        }

        $base = $pool ? $pool[array_rand($pool)] : 'Agent';

        return $base.' '.(Agent::query()->where('name', 'like', $base.'%')->count() + 1);
    }

    protected function freeAvatar(?string $gender = null, ?int $except = null): int
    {
        $usage = Agent::query()
            ->where('status', '!=', Status::Offline->value)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->selectRaw('avatar, count(*) as total')
            ->groupBy('avatar')
            ->pluck('total', 'avatar');

        $avatars = config("kantoor.avatars.{$gender}") ?: range(0, self::AVATARS - 1);

        $candidates = collect($avatars)->sortBy(fn ($avatar) => [$usage[$avatar] ?? 0, mt_rand()]);

        return (int) $candidates->first();
    }
}
