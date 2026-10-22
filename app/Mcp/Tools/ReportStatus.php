<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('report_status')]
#[Description('Report what an agent (a skill, scheduled task or session) is doing, so it shows up in the virtual office. The agent is created on its first report and keeps its desk afterwards.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class ReportStatus extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->description('Stable identifier of the agent, usually the skill or scheduled task name, e.g. "weekly-report". Use the same value on every call.')->required(),
            'status' => $schema->string()->enum(Status::reportable())->description('working = busy, waiting = needs the user, done = finished, error = stuck or failed, idle = available, sleeping = scheduled and waiting for its next run.')->required(),
            'message' => $schema->string()->description('One short line for the office screen, in the user\'s language, e.g. "Draft ready for review". No personal data.'),
            'project' => $schema->string()->description('Project or client this work belongs to, e.g. "Webshop", "Customer portal", "Internal". Sets the desk island.'),
            'role' => $schema->string()->description('Job title shown in the office, e.g. "Mail writer". Only needed on the first call or to change it.'),
            'question' => $schema->string()->description('Only with status "waiting": the question for the user, shown in the office app where the user can answer it. Fetch the answer with get_answer. Keep it short and free of personal or customer data.'),
            'options' => $schema->array()->items($schema->string())->description('Optional answer choices shown as buttons, e.g. ["Branch A", "Branch B"]. Max 6. The user can always type a free answer.'),
            'timeout_minutes' => $schema->integer()->description('After how many minutes without a report a working agent is marked as stuck. Default 30.'),
            'parent' => $schema->string()->description('For a subagent: the "agent" value of the agent that started it, so it sits next to it in the office. Not needed when the name is "<parent>-researcher", "-builder", "-reviewer" or "-tester", optionally with a number.'),
        ];
    }

    public function handle(Request $request, Office $office): Response
    {
        $request->validate([
            'agent' => 'required|string|max:80',
            'status' => 'required|string|in:'.implode(',', Status::reportable()),
            'message' => 'nullable|string|max:300',
            'project' => 'nullable|string|max:60',
            'role' => 'nullable|string|max:80',
            'question' => 'nullable|string|max:300|required_with:options',
            'options' => 'nullable',
            'timeout_minutes' => 'nullable|integer|min:1|max:1440',
            'parent' => 'nullable|string|max:80|different:agent',
        ]);

        $status = Status::from($request->get('status'));

        if ($request->get('question') && $status !== Status::Waiting) {
            return Response::error('A question is only allowed with status "waiting".');
        }

        $options = $this->options($request->get('options'));

        if (count($options) > 6) {
            return Response::error('Give at most 6 options.');
        }

        $updates = array_filter([
            'project' => $request->get('project'),
            'role' => $request->get('role'),
        ]);

        $agent = $office->agent($this->key($request->get('agent')), [
            'source' => 'mcp',
            'role' => Str::headline((string) $request->get('agent')),
            ...$updates,
        ], $updates);

        $office->adopt($agent, $request->get('parent')
            ? Agent::query()->firstWhere('key', $this->key($request->get('parent')))
            : $office->parentByName($agent->key));

        if ($request->get('timeout_minutes')) {
            $agent->forceFill(['timeout_minutes' => (int) $request->get('timeout_minutes')])->save();
        }

        $question = $request->get('question');

        if (! $question && $status === Status::Waiting && ! $agent->openQuestion) {
            $question = $request->get('message');
        }

        if ($question) {
            $office->ask($agent, $question, $options, $request->get('message'));

            return Response::text("{$agent->name} is waiting for your answer in the office. Fetch it with get_answer (agent \"{$request->get('agent')}\"), using wait_seconds to wait for it.");
        }

        $office->report($agent, $status, $request->get('message'));

        return Response::text("{$agent->name} ({$agent->role}) is now \"".$agent->status->label().'"'.($agent->project ? " on {$agent->project->name}." : '.'));
    }

    protected function options(mixed $options): array
    {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : explode('|', $options);
        }

        return array_values(array_filter(array_map(
            fn ($option) => is_scalar($option) ? trim((string) $option) : '',
            (array) $options,
        ), fn ($option) => $option !== ''));
    }

    protected function key(string $agent): string
    {
        return 'mcp:'.Str::slug($agent);
    }
}
