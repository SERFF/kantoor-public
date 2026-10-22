<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alarm extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rang_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeRinging(Builder $query): void
    {
        $query->whereNull('answered_at')->where('rang_at', '>=', now()->subMinutes(config('kantoor.alarm_minutes')));
    }

    public function isRinging(): bool
    {
        return ! $this->answered_at && $this->rang_at->gte(now()->subMinutes(config('kantoor.alarm_minutes')));
    }

    public function label(): string
    {
        return match ($this->action) {
            'regression' => 'Fout is terug',
            'frequency' => 'Fout komt vaak voor',
            'test' => 'Testmelding',
            default => 'Nieuwe fout',
        };
    }

    public function toOffice(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'project_id' => $this->project_id,
            'project' => $this->project?->name ?? $this->project_name,
            'short_id' => $this->short_id,
            'level' => $this->level,
            'action' => $this->action,
            'label' => $this->label(),
            'times_seen' => $this->times_seen,
            'url' => $this->url,
            'rang_at' => $this->rang_at->toIso8601String(),
            'until' => $this->rang_at->copy()->addMinutes(config('kantoor.alarm_minutes'))->toIso8601String(),
        ];
    }
}
