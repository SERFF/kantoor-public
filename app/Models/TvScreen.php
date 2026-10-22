<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TvScreen extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public static function issue(string $name): string
    {
        $plain = 'tv_'.Str::random(40);

        static::query()->create(['name' => $name, 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public static function findByPlain(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }

    public function touchSeen(): void
    {
        if (! $this->last_seen_at || $this->last_seen_at->lt(now()->subMinutes(5))) {
            $this->forceFill(['last_seen_at' => now()])->save();
        }
    }
}
