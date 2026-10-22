<?php

namespace App\Console\Commands;

use App\Models\TvScreen;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:tv-link {name : Naam van het scherm, bijv. "Woonkamer"} {--revoke : Trek alle links met deze naam in}')]
#[Description('Create a view-only link that shows the office on a TV without logging in')]
class IssueTvLink extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = TvScreen::query()->where('name', $name)->delete();
            $this->info("{$count} link(s) ingetrokken.");

            return self::SUCCESS;
        }

        $token = TvScreen::issue($name);

        $this->info("Tv-link voor \"{$name}\" (alleen meekijken, wordt maar één keer getoond):");
        $this->line(route('tv.screen', $token));

        return self::SUCCESS;
    }
}
