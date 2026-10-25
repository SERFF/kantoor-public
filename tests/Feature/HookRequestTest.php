<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\HookToken;
use App\Models\OfficeDay;
use App\Models\User;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HookRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = HookToken::issue('MacBook');
        Event::fake([OfficeChanged::class]);
    }

    private function hook(array $payload)
    {
        return $this->withToken($this->token)->postJson('/api/hooks/claude-code', [
            'session_id' => 'abc123',
            'cwd' => '/Users/alex/Code/acme',
            ...$payload,
        ]);
    }

    private function poll(string $request)
    {
        return $this->withToken($this->token)->getJson('/api/hooks/claude-code/requests/'.$request);
    }

    private function answer(string $answer)
    {
        $question = AgentQuestion::query()->open()->latest('id')->firstOrFail();

        return $this->actingAs(User::factory()->create())
            ->postJson('/office/questions/'.$question->id.'/answer', ['answer' => $answer]);
    }

    private function permission(array $extra = []): ?string
    {
        return $this->hook([
            'hook_event_name' => 'PermissionRequest',
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'php artisan test', 'description' => 'Runs the tests'],
            'wait' => true,
            ...$extra,
        ])->assertOk()->json('request');
    }

    public function test_a_permission_request_waits_for_an_answer_in_the_office(): void
    {
        $request = $this->permission();

        $agent = Agent::firstWhere('key', 'cc:abc123');
        $this->assertSame(Status::Waiting, $agent->status);
        $this->assertSame('Permission for: Runs the tests', $agent->openQuestion->question);
        $this->assertSame(['Allow', 'Deny', 'Answer in the terminal'], $agent->openQuestion->options);
        $this->assertSame('php artisan test', $agent->toOffice()['question']['detail']);
        $this->assertSame('permission', $agent->toOffice()['question']['kind']);

        $this->poll($request)->assertExactJson(['status' => 'pending']);

        $this->answer('Allow')->assertOk();

        $this->poll($request)->assertExactJson(['status' => 'answered', 'output' => [
            'hookSpecificOutput' => ['hookEventName' => 'PermissionRequest', 'decision' => ['behavior' => 'allow']],
        ]]);
        $this->poll($request)->assertExactJson(['status' => 'gone']);

        $this->assertSame(Status::Working, $agent->fresh()->status);
        $this->assertNull(AgentQuestion::firstWhere('request', $request)->payload['output'] ?? null);
        $this->assertSame(1, (int) OfficeDay::query()->sum('approvals'));
    }

    public function test_always_allow_echoes_the_suggested_rule(): void
    {
        $rule = ['type' => 'addRules', 'rules' => [['toolName' => 'Bash', 'ruleContent' => 'php artisan test']], 'behavior' => 'allow', 'destination' => 'localSettings'];
        $request = $this->permission(['permission_suggestions' => [$rule, ['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session']]]);

        $this->assertContains('Always allow', AgentQuestion::query()->open()->first()->options);
        $this->answer('Always allow')->assertOk();

        $this->poll($request)->assertJsonPath('output.hookSpecificOutput.decision', ['behavior' => 'allow', 'updatedPermissions' => [$rule]]);
    }

    public function test_a_typed_answer_denies_with_that_feedback(): void
    {
        $request = $this->permission();

        $this->answer('Draai alleen de unit tests')->assertOk();

        $this->poll($request)
            ->assertJsonPath('output.hookSpecificOutput.decision.behavior', 'deny')
            ->assertJsonPath('output.hookSpecificOutput.decision.message', 'The user denied this in the office and says: Draai alleen de unit tests');
    }

    public function test_answer_in_the_terminal_releases_the_hook(): void
    {
        $request = $this->permission();

        $this->answer('Answer in the terminal')->assertOk();

        $this->poll($request)->assertExactJson(['status' => 'released']);
        $this->assertSame('Waiting for your answer in the terminal', Agent::firstWhere('key', 'cc:abc123')->message);
    }

    public function test_a_hook_that_gives_up_cancels_the_question(): void
    {
        $request = $this->permission();

        $this->withToken($this->token)->deleteJson('/api/hooks/claude-code/requests/'.$request)->assertOk();

        $this->poll($request)->assertExactJson(['status' => 'released']);
        $this->assertNull(Agent::firstWhere('key', 'cc:abc123')->openQuestion);
        $question = AgentQuestion::firstWhere('request', $request);
        $this->actingAs(User::factory()->create())
            ->postJson('/office/questions/'.$question->id.'/answer', ['answer' => 'Allow'])
            ->assertStatus(409);
    }

    public function test_without_wait_it_only_shows_that_it_is_waiting(): void
    {
        $response = $this->permission(['wait' => false]);

        $this->assertNull($response);
        $agent = Agent::firstWhere('key', 'cc:abc123');
        $this->assertSame(Status::Waiting, $agent->status);
        $this->assertSame('Waiting for permission: Runs the tests', $agent->message);
        $this->assertNull($agent->openQuestion);
    }

    public function test_ask_user_question_is_answered_question_by_question(): void
    {
        $questions = [
            ['question' => 'Welke database?', 'header' => 'Database', 'options' => [['label' => 'Postgres', 'description' => 'x'], ['label' => 'SQLite', 'description' => 'y']], 'multiSelect' => false],
            ['question' => 'Tests schrijven?', 'header' => 'Tests', 'options' => [['label' => 'Ja'], ['label' => 'Nee']], 'multiSelect' => false],
        ];

        $request = $this->hook([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'AskUserQuestion',
            'tool_input' => ['questions' => $questions],
            'wait' => true,
        ])->assertOk()->json('request');

        $this->assertSame('(1/2) Welke database?', AgentQuestion::query()->open()->first()->question);
        $this->assertSame(['Postgres', 'SQLite', 'Answer in the terminal'], AgentQuestion::query()->open()->first()->options);

        $this->answer('SQLite')->assertOk();
        $this->poll($request)->assertExactJson(['status' => 'pending']);
        $this->assertSame('(2/2) Tests schrijven?', AgentQuestion::query()->open()->first()->question);

        $this->answer('Ja')->assertOk();

        $this->poll($request)->assertExactJson(['status' => 'answered', 'output' => ['hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'allow',
            'updatedInput' => ['questions' => $questions, 'answers' => ['Welke database?' => 'SQLite', 'Tests schrijven?' => 'Ja']],
        ]]]);
    }

    public function test_polling_needs_the_hook_token(): void
    {
        $request = $this->permission();

        $this->flushHeaders()->getJson('/api/hooks/claude-code/requests/'.$request)->assertUnauthorized();
    }

    public function test_edits_show_lines_added_and_removed_and_count_for_the_week(): void
    {
        $this->hook(['hook_event_name' => 'SessionStart', 'source' => 'startup']);
        $this->hook(['hook_event_name' => 'SessionStart', 'source' => 'resume']);
        $this->hook(['hook_event_name' => 'UserPromptSubmit']);
        $this->hook([
            'hook_event_name' => 'PostToolUse',
            'tool_name' => 'Edit',
            'tool_input' => ['file_path' => '/Users/alex/Code/acme/app/Planning.php'],
            'lines' => ['added' => 12, 'removed' => 3],
        ])->assertOk();

        $this->assertSame('Editing Planning.php +12 −3', Agent::firstWhere('key', 'cc:abc123')->message);

        $this->hook([
            'hook_event_name' => 'PostToolUse',
            'tool_name' => 'Edit',
            'tool_input' => ['file_path' => '/x/a.php', 'old_string' => "a\nb", 'new_string' => "a\nb\nc"],
        ]);

        $day = OfficeDay::query()->sole();
        $this->assertSame([1, 1, 2, 15, 5], [$day->sessions, $day->prompts, $day->edits, $day->lines_added, $day->lines_removed]);
    }
}
