<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id');
    }

    public function toOffice(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'agent_id' => $this->agent_id,
            'to_agent_id' => $this->to_agent_id,
            'status' => $this->status,
            'message' => $this->message,
            'text' => $this->describe(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function describe(): string
    {
        $from = $this->agent?->name ?? 'Someone';

        if ($this->type === 'handoff') {
            $to = $this->toAgent?->name ?? 'a colleague';

            return trim("{$from} passes ".($this->message ?: 'work')." on to {$to}");
        }

        if ($this->type === 'answer') {
            return "You answered a question from {$from}";
        }

        return match ($this->status) {
            'working' => "{$from} is working".($this->message ? ": {$this->message}" : ''),
            'waiting' => "{$from} is waiting for you".($this->message ? ": {$this->message}" : ''),
            'done' => "{$from} is done".($this->message ? ": {$this->message}" : ''),
            'error' => "{$from} is stuck".($this->message ? ": {$this->message}" : ''),
            'sleeping' => "{$from} goes to sleep".($this->message ? ": {$this->message}" : ''),
            'offline' => "{$from} goes home",
            default => "{$from}: ".($this->message ?: 'available'),
        };
    }
}
