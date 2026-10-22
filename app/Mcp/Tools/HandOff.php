<?php

namespace App\Mcp\Tools;

use App\Office\Office;
use App\Office\Status;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('hand_off')]
#[Description('Show in the office that one agent passes work on to another, e.g. a research skill handing its findings to a writing skill. Both agents are created if they do not exist yet.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class HandOff extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Agent that passes the work on (same identifier as in report_status).')->required(),
            'to' => $schema->string()->description('Agent that receives the work.')->required(),
            'what' => $schema->string()->description('A few Dutch words about what is passed on, e.g. "4 nieuwe bevindingen".'),
            'project' => $schema->string()->description('Project for agents that are new.'),
        ];
    }

    public function handle(Request $request, Office $office): Response
    {
        $request->validate([
            'from' => 'required|string|max:80',
            'to' => 'required|string|max:80|different:from',
            'what' => 'nullable|string|max:120',
            'project' => 'nullable|string|max:60',
        ]);

        $attributes = array_filter(['source' => 'mcp', 'project' => $request->get('project')]);

        $from = $office->agent('mcp:'.Str::slug($request->get('from')), $attributes + ['role' => Str::headline($request->get('from'))]);
        $to = $office->agent('mcp:'.Str::slug($request->get('to')), $attributes + ['role' => Str::headline($request->get('to'))]);

        $office->adopt($from, $office->parentByName($from->key));
        $office->adopt($to, $office->parentByName($to->key));

        $event = $office->handoff($from, $to, $request->get('what'));

        if (in_array($to->status, [Status::Idle, Status::Sleeping, Status::Done, Status::Offline], true)) {
            $office->report($to, Status::Working, 'Pakt '.($request->get('what') ?: 'het werk').' op');
        }

        return Response::text($event->load(['agent', 'toAgent'])->describe().'.');
    }
}
