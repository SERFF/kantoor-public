<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kantoor:demo {--minutes=5 : How long the demo runs} {--clear : Remove the demo agents}')]
#[Description('Fill the office with demo agents and let them work, hand off and get stuck, to try the live view')]
class DemoOffice extends Command
{
    protected array $cast = [
        ['research', 'Researcher', 'Internal', ['Gathering sources', 'Comparing 12 providers']],
        ['writer', 'Writer', 'Internal', ['Writing the newsletter', 'Preparing a draft']],
        ['planner', 'Planner', 'Internal', ['Preparing the weekly schedule']],
        ['shop-builder', 'Builder', 'Webshop', ['Running the tests', 'Editing CheckoutController.php']],
        ['shop-triage', 'Bug triage', 'Webshop', ['Turning a report into an issue']],
        ['shop-review', 'Reviewer', 'Webshop', ['Reading through the pull request']],
        ['portal-tickets', 'Ticket writer', 'Customer portal', ['Writing acceptance criteria']],
        ['portal-dev', 'Developer', 'Customer portal', ['Building the dashboard', 'Reading Dashboard.php']],
        ['site-seo', 'SEO analyst', 'Website', ['Fetching Search Console numbers']],
        ['site-redesign', 'Redesign builder', 'Website', ['Updating the header']],
        ['inbox', 'Inbox helper', 'Ops', ['Sweeping up newsletters']],
        ['backup', 'Backups', 'Ops', ['Checking the nightly backup']],
        ['monitor', 'Monitoring', 'Ops', ['Watching for new errors']],
    ];

    public function handle(Office $office): int
    {
        if ($this->option('clear')) {
            $count = Agent::query()->where('key', 'like', 'demo:%')->delete();
            $this->info("{$count} demo agents removed.");

            return self::SUCCESS;
        }

        $agents = collect($this->cast)->mapWithKeys(fn (array $row) => [$row[0] => [
            'agent' => $office->agent('demo:'.$row[0], ['source' => 'mcp', 'role' => $row[1], 'project' => $row[2]]),
            'lines' => $row[3],
        ]]);

        $this->info($agents->count().' demo agents put to work. Stop with Ctrl+C.');

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
                    $office->handoff($agent, $colleague->fresh(), collect(['3 sources', 'a design', 'a PR', 'the numbers', 'a bug report'])->random());
                    $office->report($colleague->fresh(), Status::Working, 'Picking up the work');
                }
            } elseif ($roll <= 80) {
                $office->report($agent, Status::Waiting, collect(['6 drafts ready to send', 'Question about the schedule', 'PR needs approval'])->random());
            } elseif ($roll <= 94) {
                $office->report($agent, Status::Done, collect(['Found 3 sources', 'Design finished', 'PR merged', 'Report sent'])->random());
            } else {
                $office->report($agent, Status::Error, 'Tests failed');
            }

            $this->line(now()->format('H:i:s').' '.$agent->fresh()->name.' → '.$agent->fresh()->status->label());

            usleep(random_int(1200, 3000) * 1000);
        }

        return self::SUCCESS;
    }
}
