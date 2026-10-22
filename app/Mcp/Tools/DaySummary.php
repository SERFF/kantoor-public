<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Office\Logbook;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('day_summary')]
#[Description('End-of-day recap of the office: what each agent delivered per project on a day (default today) and, for today, who is still working, waiting or stuck. Use it for "what got done today" or a daily wrap-up.')]
#[IsReadOnly]
class DaySummary extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Day as YYYY-MM-DD. Default today (Europe/Amsterdam).'),
            'project' => $schema->string()->description('Only this project, e.g. "Webshop".'),
        ];
    }

    public function handle(Request $request, Logbook $logbook): Response
    {
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'project' => 'nullable|string|max:60',
        ]);

        $project = null;

        if ($request->get('project')) {
            $project = Project::query()->firstWhere('slug', Str::slug($request->get('project')))
                ?? Project::query()->whereNotNull('match')->get()->first(fn (Project $item) => $item->matches(Str::slug($request->get('project'))));

            if (! $project) {
                return Response::text('Dat project ken ik niet in het kantoor.');
            }
        }

        return Response::text($logbook->summary($request->get('date'), $project));
    }
}
