<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('kantoor:project {name? : Projectnaam; zonder naam toont het de lijst} {--match= : Komma-gescheiden mapnamen of patronen die bij dit project horen, bijv. "webshop*,shop-api"} {--position= : Volgorde van het eiland} {--rename= : Nieuwe naam} {--merge= : Verplaats agents van dit project hierheen en verwijder het}')]
#[Description('List projects, or create and configure a project (desk island)')]
class ManageProject extends Command
{
    public function handle(): int
    {
        if (! $this->argument('name')) {
            $this->table(['Positie', 'Slug', 'Naam', 'Match', 'Agents'], Project::query()->withCount('agents')->orderBy('position')->get()
                ->map(fn (Project $project) => [$project->position, $project->slug, $project->name, $project->match, $project->agents_count]));

            return self::SUCCESS;
        }

        $project = Project::resolve($this->argument('name'));

        if ($this->option('merge')) {
            $other = Project::query()->where('slug', Str::slug($this->option('merge')))->first();

            if (! $other || $other->is($project)) {
                $this->error('Project om samen te voegen niet gevonden.');

                return self::FAILURE;
            }

            Agent::query()->where('project_id', $other->id)->update(['project_id' => $project->id]);
            $project->match = implode(',', array_unique(array_filter([...explode(',', (string) $project->match), $other->slug, ...explode(',', (string) $other->match)])));
            $other->delete();
        }

        if ($this->option('match') !== null) {
            $project->match = $this->option('match') ?: null;
        }

        if ($this->option('position') !== null) {
            $project->position = (int) $this->option('position');
        }

        if ($this->option('rename')) {
            $project->name = $this->option('rename');
        }

        $project->save();

        $this->info("Project {$project->name} ({$project->slug}) opgeslagen.");

        return self::SUCCESS;
    }
}
