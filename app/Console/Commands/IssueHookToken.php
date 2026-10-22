<?php

namespace App\Console\Commands;

use App\Models\HookToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:hook-token {name : Name of the machine, e.g. "MacBook Pro"} {--revoke : Revoke all tokens with this name}')]
#[Description('Create a token for the Claude Code hooks on one machine and print the settings.json snippet')]
class IssueHookToken extends Command
{
    public const EVENTS = ['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Notification', 'Stop', 'SubagentStart', 'SubagentStop', 'SessionEnd', 'PreCompact'];

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = HookToken::query()->where('kind', HookToken::CLAUDE_CODE)->where('name', $name)->delete();
            $this->info("{$count} token(s) revoked.");

            return self::SUCCESS;
        }

        $token = HookToken::issue($name);

        $this->info("Token for \"{$name}\" (shown only once):");
        $this->line($token);
        $this->newLine();
        $this->info('Add this to ~/.claude/settings.json on that machine (merge it with any existing "hooks"):');
        $this->line(json_encode(['hooks' => self::hooks($token)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    public static function hooks(string $token): array
    {
        $command = sprintf(
            "curl -s -m 2 -o /dev/null -X POST -H 'Authorization: Bearer %s' -H 'Content-Type: application/json' --data-binary @- %s || true",
            $token,
            rtrim(config('app.url'), '/').'/api/hooks/claude-code',
        );

        $hooks = [];

        foreach (self::EVENTS as $event) {
            $entry = ['hooks' => [['type' => 'command', 'command' => $command, 'timeout' => 5]]];

            if (in_array($event, ['PreToolUse', 'PostToolUse'], true)) {
                $entry = ['matcher' => '*', ...$entry];
            }

            $hooks[$event] = [$entry];
        }

        return $hooks;
    }
}
