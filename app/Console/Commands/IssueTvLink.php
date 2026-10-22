<?php

namespace App\Console\Commands;

use App\Models\TvScreen;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:tv-link {name : Name of the screen, e.g. "Living room"} {--revoke : Revoke all links with this name}')]
#[Description('Create a view-only link that shows the office on a TV without logging in')]
class IssueTvLink extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if ($this->option('revoke')) {
            $count = TvScreen::query()->where('name', $name)->delete();
            $this->info("{$count} link(s) revoked.");

            return self::SUCCESS;
        }

        $token = TvScreen::issue($name);

        $this->info("TV link for \"{$name}\" (view only, shown only once):");
        $this->line(route('tv.screen', $token));

        return self::SUCCESS;
    }
}
