<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\AgentQuestion;
use App\Models\Alarm;
use App\Models\LogEntry;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:check')]
#[Description('Mark silent agents as stuck, let finished agents rest, send available agents and ended sessions home and prune old events, questions and log entries')]
class CheckOffice extends Command
{
    public function handle(Office $office): int
    {
        Agent::query()->where('status', Status::Working->value)->get()
            ->filter(fn (Agent $agent) => $agent->last_seen_at?->lt(now()->subMinutes($agent->timeout())))
            ->each(fn (Agent $agent) => $office->report($agent, Status::Error, 'No sign of life since '.$agent->last_seen_at->format('H:i')));

        Agent::query()->where('status', Status::Done->value)
            ->where('status_changed_at', '<', now()->subMinutes(config('kantoor.done_to_idle_minutes')))
            ->get()
            ->each(function (Agent $agent) use ($office) {
                $agent->forceFill(['status' => Status::Idle, 'status_changed_at' => now()])->save();
                $office->broadcast($agent);
            });

        Agent::query()->where('status', Status::Idle->value)
            ->where('status_changed_at', '<', now()->subMinutes(config('kantoor.idle_offline_after_minutes')))
            ->get()
            ->each(fn (Agent $agent) => $office->report($agent, Status::Offline));

        Agent::query()->whereIn('source', ['claude_code', 'subagent'])
            ->whereIn('status', [Status::Idle->value, Status::Done->value, Status::Error->value, Status::Waiting->value])
            ->where('last_seen_at', '<', now()->subMinutes(config('kantoor.offline_after_minutes')))
            ->get()
            ->each(fn (Agent $agent) => $office->report($agent, Status::Offline));

        Agent::query()->whereIn('source', ['claude_code', 'subagent'])
            ->where('status', Status::Offline->value)
            ->where('status_changed_at', '<', now()->subDays(config('kantoor.forget_offline_after_days')))
            ->delete();

        AgentEvent::query()->where('created_at', '<', now()->subDays(config('kantoor.keep_events_days')))->delete();

        Alarm::query()->where('rang_at', '<', now()->subDays(config('kantoor.keep_alarms_days')))->delete();

        LogEntry::query()->where('created_at', '<', now()->subDays(config('kantoor.keep_log_days')))->delete();

        AgentQuestion::query()->where('updated_at', '<', now()->subDays(config('kantoor.keep_questions_days')))->delete();

        return self::SUCCESS;
    }
}
