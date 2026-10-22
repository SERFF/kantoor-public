<?php

namespace App\Events;

use App\Models\Agent;
use App\Models\AgentEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OfficeChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<int, Agent>  $agents
     */
    public function __construct(public array $agents, public ?AgentEvent $event = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('office')];
    }

    public function broadcastAs(): string
    {
        return 'office.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'agents' => array_map(fn (Agent $agent) => $agent->toOffice(), $this->agents),
            'projects' => array_values(array_filter(array_map(fn (Agent $agent) => $agent->project?->toOffice(), $this->agents))),
            'event' => $this->event?->toOffice(),
        ];
    }
}
