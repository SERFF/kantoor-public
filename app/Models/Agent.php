<?php

namespace App\Models;

use App\Office\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'status_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AgentQuestion::class);
    }

    public function openQuestion(): HasOne
    {
        return $this->hasOne(AgentQuestion::class)->ofMany(['id' => 'max'], fn ($query) => $query->open());
    }

    public function timeout(): int
    {
        return $this->timeout_minutes ?: (int) config("kantoor.timeouts.{$this->source}", config('kantoor.timeouts.mcp'));
    }

    public function toOffice(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'role' => $this->role,
            'source' => $this->source,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'avatar' => $this->avatar,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->message,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'question' => $this->openQuestion?->toOffice(),
        ];
    }
}
