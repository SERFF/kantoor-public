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
#[Description('Fetch the user\'s answer to the question an agent asked with report_status (status "waiting" plus "question"). The user answers in the office app. Call it before continuing work that waits on that question, and at the start of every new user message while the agent was waiting. Returns the answer once; afterwards the agent is shown as working again.')]
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
        ];
    }

    public function handle(Request $request, Office $office): Response
    {
        $request->validate([
            'agent' => 'required|string|max:80',
            'wait_seconds' => 'nullable|integer|min:0|max:'.self::MAX_WAIT,
        ]);

        $agent = Agent::query()->firstWhere('key', 'mcp:'.Str::slug($request->get('agent')));

        if (! $agent) {
            return Response::text('Deze agent is onbekend in het kantoor; er staat geen vraag open.');
        }

        $deadline = microtime(true) + (int) $request->get('wait_seconds', 0);

        do {
            if ($question = $office->collect($agent)) {
                return Response::text("Antwoord op \"{$question->question}\":\n{$question->answer}");
            }

            if (! $agent->openQuestion()->exists()) {
                return Response::text('Er staat geen vraag open en er is geen nieuw antwoord.');
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(2_000_000);
        } while (true);

        return Response::text('De vraag is nog niet beantwoord. Vraag het in het gesprek of probeer later opnieuw.');
    }
}
