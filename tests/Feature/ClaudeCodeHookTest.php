<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\HookToken;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClaudeCodeHookTest extends TestCase
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
            'cwd' => '/Users/sam/Code/webshop/app/Http',
            ...$payload,
        ]);
    }

    public function test_hooks_require_a_valid_token(): void
    {
        $this->postJson('/api/hooks/claude-code', ['session_id' => 'x', 'hook_event_name' => 'Stop'])->assertUnauthorized();
        $this->withToken('kh_wrong')->postJson('/api/hooks/claude-code', ['session_id' => 'x', 'hook_event_name' => 'Stop'])->assertUnauthorized();
    }

    public function test_a_session_becomes_a_colleague_on_the_island_of_its_repo(): void
    {
        $this->hook(['hook_event_name' => 'SessionStart'])->assertOk();

        $agent = Agent::firstWhere('key', 'cc:abc123');
        $this->assertSame('webshop', $agent->project->slug);
        $this->assertSame('claude_code', $agent->source);
        $this->assertSame(Status::Idle, $agent->status);

        Event::assertDispatched(OfficeChanged::class);
    }

    public function test_tool_use_shows_what_it_is_doing_without_file_contents(): void
    {
        $this->hook(['hook_event_name' => 'UserPromptSubmit']);
        $this->hook([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Edit',
            'tool_input' => ['file_path' => '/Users/sam/Code/webshop/app/Models/Planning.php', 'old_string' => 'secret', 'new_string' => 'hidden'],
        ])->assertOk();

        $agent = Agent::firstWhere('key', 'cc:abc123');
        $this->assertSame(Status::Working, $agent->status);
        $this->assertSame('Editing Planning.php', $agent->message);
        $this->assertDatabaseMissing('agent_events', ['message' => 'secret']);
    }

    public function test_notification_means_waiting_and_stop_means_done(): void
    {
        $this->hook(['hook_event_name' => 'Notification', 'message' => 'Claude needs your permission to use Bash']);
        $this->assertSame(Status::Waiting, Agent::firstWhere('key', 'cc:abc123')->status);
        $this->assertSame('Waiting for permission', Agent::firstWhere('key', 'cc:abc123')->message);

        $this->hook(['hook_event_name' => 'Stop']);
        $this->assertSame(Status::Done, Agent::firstWhere('key', 'cc:abc123')->status);
    }

    public function test_subagents_get_work_handed_over_and_back(): void
    {
        $this->hook(['hook_event_name' => 'UserPromptSubmit']);
        $this->hook(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent', 'tool_input' => ['subagent_type' => 'Explore', 'description' => 'Find the mail settings']]);
        $this->hook(['hook_event_name' => 'PostToolUse', 'tool_name' => 'Agent', 'tool_input' => ['subagent_type' => 'Explore']]);
        $this->hook(['hook_event_name' => 'SubagentStart', 'agent_id' => 'a1', 'agent_type' => 'Explore']);

        $parent = Agent::firstWhere('key', 'cc:abc123');
        $sub = Agent::firstWhere('key', 'cc:abc123:a1');

        $this->assertSame($parent->id, $sub->parent_id);
        $this->assertSame(Status::Working, $sub->status);
        $this->assertSame('Subagent · Explore', $sub->role);
        $this->assertSame($parent->project_id, $sub->project_id);
        $this->assertDatabaseHas('agent_events', ['type' => 'handoff', 'agent_id' => $parent->id, 'to_agent_id' => $sub->id, 'message' => 'Find the mail settings']);

        $this->hook(['hook_event_name' => 'PreToolUse', 'agent_id' => 'a1', 'tool_name' => 'Grep', 'tool_input' => []]);
        $this->assertSame('Searching the code', $sub->fresh()->message);

        $this->hook(['hook_event_name' => 'Stop']);
        $this->assertSame(Status::Working, $sub->fresh()->status);

        $this->hook(['hook_event_name' => 'SubagentStop', 'agent_id' => 'a1', 'agent_type' => 'Explore']);

        $this->assertSame(Status::Done, $sub->fresh()->status);
        $this->assertDatabaseHas('agent_events', ['type' => 'handoff', 'agent_id' => $sub->id, 'to_agent_id' => $parent->id]);

        $this->hook(['hook_event_name' => 'SessionEnd']);
        $this->assertSame(Status::Offline, $parent->fresh()->status);
        $this->assertSame(Status::Offline, $sub->fresh()->status);
    }

    public function test_parallel_subagents_of_the_same_type_each_get_a_desk(): void
    {
        $this->hook(['hook_event_name' => 'UserPromptSubmit']);

        foreach (['Build A', 'Build B', 'Build C'] as $description) {
            $this->hook(['hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent', 'tool_input' => ['subagent_type' => 'general-purpose', 'description' => $description]]);
        }

        foreach (['x1', 'x2', 'x3'] as $id) {
            $this->hook(['hook_event_name' => 'SubagentStart', 'agent_id' => $id, 'agent_type' => 'general-purpose']);
        }

        $this->assertSame(3, Agent::where('source', 'subagent')->where('status', Status::Working->value)->count());
        $this->assertSame('Build B', Agent::firstWhere('key', 'cc:abc123:x2')->message);

        $this->hook(['hook_event_name' => 'SubagentStop', 'agent_id' => 'x1', 'agent_type' => 'general-purpose']);

        $this->assertSame(Status::Done, Agent::firstWhere('key', 'cc:abc123:x1')->status);
        $this->assertSame(Status::Working, Agent::firstWhere('key', 'cc:abc123:x3')->status);
    }

    public function test_project_falls_back_to_folder_name_and_respects_match_patterns(): void
    {
        $this->artisan('kantoor:project', ['name' => 'Webshop', '--match' => 'webshop*,shop-api'])->assertSuccessful();

        $this->withToken($this->token)->postJson('/api/hooks/claude-code', [
            'session_id' => 'def456',
            'hook_event_name' => 'SessionStart',
            'cwd' => '/Users/sam/Herd/webshop-2026',
        ])->assertOk();

        $this->assertSame('Webshop', Agent::firstWhere('key', 'cc:def456')->project->name);
        $this->assertSame(1, AgentEvent::count());
    }
}
