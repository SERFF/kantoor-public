<?php

namespace App\Events;

use App\Models\Alarm;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AlarmsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public ?int $ringing = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('office')];
    }

    public function broadcastAs(): string
    {
        return 'office.alarms';
    }

    public function broadcastWith(): array
    {
        return [
            'alarms' => Alarm::query()->with('project')->ringing()->latest('rang_at')->limit(10)->get()->map->toOffice()->all(),
            'ringing' => $this->ringing,
        ];
    }
}
