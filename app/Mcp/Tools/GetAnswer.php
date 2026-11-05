<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use App\Office\Office;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_answer')]
#[Description('Fetch the user\'s answer to the question an agent asked with report_status (status "waiting" plus "question"). The user answers in the office app. Call it before continuing work that waits on that question, and at the start of every new user message while the agent was waiting. Returns the answer once; afterwards the agent is shown as working again. If the user answered in the conversation instead of the office app, call it with answered_in_chat true: that closes the question in the office.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class GetAnswer extends Tool
{
    public const MAX_WAIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->description('Same agent identifier as in report_status.')->required(),
            'wait_seconds' => $schema->integer()->description('Wait up to this many seconds for an answer (max 50). Use 0 to only check. Call again if it is still open.'),
            'answered_in_chat' => $schema->boolean()->description('Set to true when the user answered the question in the conversation. An answer given in the office app is still returned; otherwise the open question is closed as answered in the chat.'),
        ];
    }

    public function handle(Request $request, Office $office): Response
    {
        $request->validate([
            'agent' => 'required|string|max:80',
            'wait_seconds' => 'nullable|integer|min:0|max:'.self::MAX_WAIT,
            'answered_in_chat' => 'nullable|boolean',
        ]);

        $agent = Agent::query()->firstWhere('key', 'mcp:'.Str::slug($request->get('agent')));

        if (! $agent) {
            return Response::text('This agent is unknown in the office; there is no open question.');
        }

        if ($request->boolean('answered_in_chat')) {
            if ($question = $office->collect($agent)) {
                return Response::text("There was also an answer in the office to \"{$question->question}\":\n{$question->answer}");
            }

            return $office->answeredInChat($agent)
                ? Response::text('The question is closed in the office as answered in the chat.')
                : Response::text('There is no open question.');
        }

        $deadline = microtime(true) + (int) $request->get('wait_seconds', 0);

        do {
            if ($question = $office->collect($agent)) {
                return Response::text("Answer to \"{$question->question}\":\n{$question->answer}");
            }

            if (! $agent->openQuestion()->exists()) {
                return Response::text('There is no open question and no new answer.');
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(2_000_000);
        } while (true);

        return Response::text('The question has not been answered yet in the office. If the user answered in the conversation, call get_answer with answered_in_chat true.');
    }
}
