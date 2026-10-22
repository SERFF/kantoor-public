<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('kantoor:project {name? : Project name; without a name it shows the list} {--match= : Comma-separated folder names or patterns that belong to this project, e.g. "webshop*,shop-api"} {--position= : Order of the island} {--rename= : New name} {--merge= : Move the agents of this project here and delete it}')]
#[Description('List projects, or create and configure a project (desk island)')]
class ManageProject extends Command
{
    public function handle(): int
    {
        if (! $this->argument('name')) {
            $this->table(['Position', 'Slug', 'Name', 'Match', 'Agents'], Project::query()->withCount('agents')->orderBy('position')->get()
                ->map(fn (Project $project) => [$project->position, $project->slug, $project->name, $project->match, $project->agents_count]));

            return self::SUCCESS;
        }

        $project = Project::resolve($this->argument('name'));

        if ($this->option('merge')) {
            $other = Project::query()->where('slug', Str::slug($this->option('merge')))->first();

            if (! $other || $other->is($project)) {
                $this->error('Project to merge not found.');

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

        $this->info("Project {$project->name} ({$project->slug}) saved.");

        return self::SUCCESS;
    }
}
