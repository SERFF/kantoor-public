<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentQuestion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'payload' => 'array',
            'answered_at' => 'datetime',
            'collected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('answered_at')->whereNull('cancelled_at');
    }

    public function scopeUncollected(Builder $query): void
    {
        $query->whereNotNull('answered_at')->whereNull('collected_at')->whereNull('cancelled_at');
    }

    public function isOpen(): bool
    {
        return $this->answered_at === null && $this->cancelled_at === null;
    }

    public function toOffice(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'question' => $this->question,
            'options' => $this->options ?: [],
            'detail' => $this->payload['detail'] ?? null,
            'asked_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
