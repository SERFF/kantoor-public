<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Models\Agent;
use App\Models\AgentEvent;
use App\Models\User;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_office_page_and_state(): void
    {
        $office = app(Office::class);
        $gone = $office->agent('cc:old', ['source' => 'claude_code']);
        $office->report($gone, Status::Offline);
        $gone->forceFill(['status_changed_at' => now()->subHour()])->save();
        $agent = $office->agent('mcp:research', ['project' => 'Intern', 'role' => 'Onderzoeker']);
        $office->report($agent, Status::Working, 'Speurt websites af');

        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk()->assertSee('Het kantoor');

        $this->getJson('/office/state')
            ->assertOk()
            ->assertJsonCount(1, 'agents')
            ->assertJsonPath('agents.0.message', 'Speurt websites af')
            ->assertJsonPath('projects.0.name', 'Intern')
            ->assertJsonPath('events.0.text', "{$agent->name} is aan het werk: Speurt websites af");
    }

    public function test_agent_can_be_renamed_moved_and_released(): void
    {
        $office = app(Office::class);
        $agent = $office->agent('mcp:research');
        $office->report($agent, Status::Error, 'Tests mislukt');

        $this->actingAs(User::factory()->create());

        $this->patchJson("/office/agents/{$agent->id}", ['name' => 'Noor', 'project' => 'Webshop'])
            ->assertOk()
            ->assertJsonPath('agent.name', 'Noor');

        $this->patchJson("/office/agents/{$agent->id}", ['status' => 'idle'])->assertOk();
        $this->patchJson("/office/agents/{$agent->id}", ['status' => 'working'])->assertUnprocessable();

        $agent->refresh();
        $this->assertSame('Webshop', $agent->project->name);
        $this->assertSame(Status::Idle, $agent->status);
    }

    public function test_check_marks_silent_agents_and_sends_sessions_home(): void
    {
        $office = app(Office::class);

        $silent = $office->report($office->agent('mcp:writer'), Status::Working, 'Bezig');
        $silent->forceFill(['last_seen_at' => now()->subHour()])->save();

        $finished = $office->report($office->agent('mcp:planner'), Status::Done, 'Klaar');
        $finished->forceFill(['status_changed_at' => now()->subHour()])->save();

        $session = $office->report($office->agent('cc:s1', ['source' => 'claude_code']), Status::Done);
        $session->forceFill(['last_seen_at' => now()->subHours(3), 'status_changed_at' => now()->subHours(3)])->save();

        $forgotten = $office->report($office->agent('cc:s2', ['source' => 'claude_code']), Status::Offline);
        $forgotten->forceFill(['status_changed_at' => now()->subDays(10)])->save();

        AgentEvent::query()->create(['agent_id' => $silent->id, 'type' => 'status', 'status' => 'working', 'created_at' => now()->subDays(40)]);

        $this->artisan('kantoor:check')->assertSuccessful();

        $this->assertSame(Status::Error, $silent->fresh()->status);
        $this->assertStringStartsWith('Geen teken van leven', $silent->fresh()->message);
        $this->assertSame(Status::Idle, $finished->fresh()->status);
        $this->assertSame(Status::Offline, $session->fresh()->status);
        $this->assertNull(Agent::find($forgotten->id));
        $this->assertSame(0, AgentEvent::query()->where('created_at', '<', now()->subDays(30))->count());
    }

    public function test_available_agents_go_home_early(): void
    {
        $office = app(Office::class);

        $resting = $office->report($office->agent('mcp:inbox'), Status::Idle);
        $resting->forceFill(['status_changed_at' => now()->subMinutes(45)])->save();

        $fresh = $office->report($office->agent('mcp:webshop'), Status::Idle);
        $fresh->forceFill(['status_changed_at' => now()->subMinutes(10)])->save();

        $asking = $office->report($office->agent('mcp:portal'), Status::Waiting, 'Welke branch?');
        $asking->forceFill(['last_seen_at' => now()->subMinutes(45), 'status_changed_at' => now()->subMinutes(45)])->save();

        $this->artisan('kantoor:check')->assertSuccessful();

        $this->assertSame(Status::Offline, $resting->fresh()->status);
        $this->assertSame(Status::Idle, $fresh->fresh()->status);
        $this->assertSame(Status::Waiting, $asking->fresh()->status);
    }

    public function test_name_matches_the_portrait(): void
    {
        $office = app(Office::class);

        foreach (range(1, 30) as $i) {
            $agent = $office->agent("mcp:agent-{$i}");
            $this->assertSame($office->genderOfAvatar($agent->avatar), $office->genderOfName($agent->name), $agent->name);
        }

        $named = $office->agent('mcp:named', ['name' => 'Femke']);
        $this->assertSame('woman', $office->genderOfAvatar($named->avatar));

        $this->actingAs(User::factory()->create());
        $this->patchJson("/office/agents/{$named->id}", ['name' => 'Bram'])->assertOk();
        $this->assertSame('man', $office->genderOfAvatar($named->fresh()->avatar));
    }

    public function test_hook_token_command_prints_settings(): void
    {
        $this->artisan('kantoor:hook-token', ['name' => 'MacBook'])
            ->expectsOutputToContain('/api/hooks/claude-code')
            ->assertSuccessful();

        $this->assertDatabaseCount('hook_tokens', 1);
    }
}
