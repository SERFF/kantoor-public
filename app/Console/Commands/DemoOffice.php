<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:demo {--minutes=5 : Hoe lang de demo doorloopt} {--clear : Verwijder de demo-agents}')]
#[Description('Fill the office with demo agents and let them work, hand off and get stuck, to try the live view')]
class DemoOffice extends Command
{
    protected array $cast = [
        ['research', 'Onderzoeker', 'Intern', ['Zoekt bronnen bij elkaar', 'Vergelijkt 12 aanbieders']],
        ['writer', 'Schrijver', 'Intern', ['Schrijft de nieuwsbrief', 'Zet een concept klaar']],
        ['planner', 'Planner', 'Intern', ['Zet de weekplanning klaar']],
        ['shop-bouwer', 'Bouwer', 'Webshop', ['Draait de tests', 'Bewerkt CheckoutController.php']],
        ['shop-triage', 'Bug-triage', 'Webshop', ['Zet een melding om naar een issue']],
        ['shop-review', 'Reviewer', 'Webshop', ['Leest de pull request door']],
        ['portal-tickets', 'Ticketschrijver', 'Klantportaal', ['Schrijft acceptatiecriteria']],
        ['portal-dev', 'Ontwikkelaar', 'Klantportaal', ['Bouwt het dashboard', 'Leest Dashboard.php']],
        ['site-seo', 'SEO-analist', 'Website', ['Haalt Search Console-cijfers op']],
        ['site-redesign', 'Redesign-bouwer', 'Website', ['Werkt de header bij']],
        ['inbox', 'Inbox-helper', 'Ops', ['Veegt nieuwsbrieven bij elkaar']],
        ['backup', 'Back-ups', 'Ops', ['Controleert de nachtelijke back-up']],
        ['monitor', 'Monitoring', 'Ops', ['Kijkt naar nieuwe fouten']],
    ];

    public function handle(Office $office): int
    {
        if ($this->option('clear')) {
            $count = Agent::query()->where('key', 'like', 'demo:%')->delete();
            $this->info("{$count} demo-agents verwijderd.");

            return self::SUCCESS;
        }

        $agents = collect($this->cast)->mapWithKeys(fn (array $row) => [$row[0] => [
            'agent' => $office->agent('demo:'.$row[0], ['source' => 'mcp', 'role' => $row[1], 'project' => $row[2]]),
            'lines' => $row[3],
        ]]);

        $this->info($agents->count().' demo-agents aan het werk gezet. Stoppen met Ctrl+C.');

        $until = now()->addMinutes((int) $this->option('minutes'));

        while (now()->lt($until)) {
            $pick = $agents->random();
            $agent = $pick['agent']->fresh();
            $roll = random_int(1, 100);

            if ($roll <= 45) {
                $office->report($agent, Status::Working, $pick['lines'][array_rand($pick['lines'])]);
            } elseif ($roll <= 70) {
                $colleague = $agents->filter(fn ($row) => $row['agent']->project_id === $agent->project_id && $row['agent']->id !== $agent->id)->random()['agent'] ?? null;

                if ($colleague) {
                    $office->handoff($agent, $colleague->fresh(), collect(['3 bronnen', 'een ontwerp', 'een PR', 'de cijfers', 'een bugmelding'])->random());
                    $office->report($colleague->fresh(), Status::Working, 'Pakt het werk op');
                }
            } elseif ($roll <= 80) {
                $office->report($agent, Status::Waiting, collect(['6 concepten klaar om te versturen', 'Vraag over de planning', 'Akkoord nodig op de PR'])->random());
            } elseif ($roll <= 94) {
                $office->report($agent, Status::Done, collect(['3 bronnen gevonden', 'Ontwerp afgerond', 'PR gemerged', 'Rapport verstuurd'])->random());
            } else {
                $office->report($agent, Status::Error, 'Tests mislukt');
            }

            $this->line(now()->format('H:i:s').' '.$agent->fresh()->name.' → '.$agent->fresh()->status->label());

            usleep(random_int(1200, 3000) * 1000);
        }

        return self::SUCCESS;
    }
}
