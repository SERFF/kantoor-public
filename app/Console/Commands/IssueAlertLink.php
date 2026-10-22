<?php

namespace App\Console\Commands;

use App\Models\HookToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:alert-link {name=Monitoring : Name of the integration} {--revoke : Revoke all alert links with this name}')]
#[Description('Create a webhook url for error alerts, so new errors ring the phone in the office')]
class IssueAlertLink extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = HookToken::query()->where('kind', HookToken::ALERT)->where('name', $name)->delete();
            $this->info("{$count} link(s) revoked.");

            return self::SUCCESS;
        }

        $token = HookToken::issue($name, HookToken::ALERT);

        $this->info('Webhook URL for an alert rule in your error monitor (shown only once):');
        $this->line(route('hooks.alerts', $token));

        return self::SUCCESS;
    }
}
