<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Office\Status;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('office_overview')]
#[Description('List the agents in the office with their project, status and last message. Use it to answer "what are my agents doing" or "who is waiting for me".')]
#[IsReadOnly]
class OfficeOverview extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_map(fn (Status $status) => $status->value, Status::cases()))->description('Only agents with this status.'),
            'include_offline' => $schema->boolean()->description('Also list agents that went home. Default false.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $agents = Agent::query()
            ->with('project')
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(! $request->get('include_offline') && ! $request->get('status'), fn ($query) => $query->where('status', '!=', Status::Offline->value))
            ->orderBy('project_id')
            ->orderBy('name')
            ->get();

        if ($agents->isEmpty()) {
            return Response::text('Er zijn geen agents die hieraan voldoen.');
        }

        return Response::text($agents->map(fn (Agent $agent) => sprintf(
            '- %s (%s, %s): %s%s, %s',
            $agent->name,
            $agent->role ?: $agent->source,
            $agent->project?->name ?? 'geen project',
            $agent->status->label(),
            $agent->message ? ' – '.$agent->message : '',
            $agent->last_seen_at?->locale('nl')->diffForHumans() ?? 'nooit gezien',
        ))->implode("\n"));
    }
}
