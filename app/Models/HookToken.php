<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HookToken extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public const CLAUDE_CODE = 'claude_code';

    public const ALERT = 'alert';

    public static function issue(string $name, string $kind = self::CLAUDE_CODE): string
    {
        $plain = ($kind === self::ALERT ? 'ka_' : 'kh_').Str::random(48);

        static::query()->create(['name' => $name, 'kind' => $kind, 'token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public static function findByPlain(?string $plain, string $kind = self::CLAUDE_CODE): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::query()->where('kind', $kind)->where('token_hash', hash('sha256', $plain))->first();
    }

    public function touchUsed(): void
    {
        if (! $this->last_used_at || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->save();
        }
    }
}
