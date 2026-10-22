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
        $from = $this->agent?->name ?? 'Iemand';

        if ($this->type === 'handoff') {
            $to = $this->toAgent?->name ?? 'een collega';

            return trim("{$from} geeft ".($this->message ?: 'werk')." door aan {$to}");
        }

        if ($this->type === 'answer') {
            return "Je beantwoordde een vraag van {$from}";
        }

        return match ($this->status) {
            'working' => "{$from} is aan het werk".($this->message ? ": {$this->message}" : ''),
            'waiting' => "{$from} wacht op jou".($this->message ? ": {$this->message}" : ''),
            'done' => "{$from} is klaar".($this->message ? ": {$this->message}" : ''),
            'error' => "{$from} loopt vast".($this->message ? ": {$this->message}" : ''),
            'sleeping' => "{$from} gaat slapen".($this->message ? ": {$this->message}" : ''),
            'offline' => "{$from} gaat naar huis",
            default => "{$from}: ".($this->message ?: 'beschikbaar'),
        };
    }
}
