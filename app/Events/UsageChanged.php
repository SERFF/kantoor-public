<?php

namespace App\Events;

use App\Models\UsageLimit;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class UsageChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public UsageLimit $limit) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('office')];
    }

    public function broadcastAs(): string
    {
        return 'office.usage';
    }

    public function broadcastWith(): array
    {
        return ['usage' => $this->limit->toOffice()];
    }
}
