<?php

namespace App\Console\Commands;

use App\Models\HookToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:alert-link {name=Monitoring : Naam van de koppeling} {--revoke : Trek alle alert-links met deze naam in}')]
#[Description('Create a webhook url for error alerts, so new errors ring the phone in the office')]
class IssueAlertLink extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = HookToken::query()->where('kind', HookToken::ALERT)->where('name', $name)->delete();
            $this->info("{$count} link(s) ingetrokken.");

            return self::SUCCESS;
        }

        $token = HookToken::issue($name, HookToken::ALERT);

        $this->info('Webhook-URL voor een alertregel in je error-monitor (wordt maar één keer getoond):');
        $this->line(route('hooks.alerts', $token));

        return self::SUCCESS;
    }
}
