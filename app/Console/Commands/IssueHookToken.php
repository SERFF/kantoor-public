<?php

namespace App\Console\Commands;

use App\Models\HookToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:hook-token {name : Name of the machine, e.g. "MacBook Pro"} {--revoke : Revoke all tokens with this name}')]
#[Description('Create a token for the Claude Code hooks on one machine and print the install command')]
class IssueHookToken extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = HookToken::query()->where('kind', HookToken::CLAUDE_CODE)->where('name', $name)->delete();
            $this->info("{$count} token(s) revoked.");

            return self::SUCCESS;
        }

        $token = HookToken::issue($name);

        $this->info('Token for "'.$name.'" (shown only once):');
        $this->line($token);
        $this->newLine();
        $this->info('Install the hooks on that machine with:');
        $this->line(self::installCommand($token));
        $this->newLine();
        $this->line('The script backs up ~/.claude/settings.json first and shows you the change.');

        return self::SUCCESS;
    }

    public static function installCommand(string $token): string
    {
        return sprintf('curl -fsSL %s | bash -s -- %s', route('hooks.install'), $token);
    }
}
