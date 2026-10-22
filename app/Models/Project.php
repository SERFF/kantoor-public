<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $guarded = [];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public static function resolve(?string $name): ?self
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            return null;
        }

        return static::query()->where('slug', $slug)->first()
            ?? static::query()->whereNotNull('match')->get()->first(fn (self $project) => $project->matches($slug))
            ?? static::query()->create([
                'slug' => $slug,
                'name' => $name,
                'position' => (int) static::query()->max('position') + 1,
            ]);
    }

    public function matches(string $slug): bool
    {
        foreach (array_filter(array_map('trim', explode(',', (string) $this->match))) as $pattern) {
            if (Str::is(Str::lower($pattern), $slug)) {
                return true;
            }
        }

        return false;
    }

    public function toOffice(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'position' => $this->position,
        ];
    }
}
