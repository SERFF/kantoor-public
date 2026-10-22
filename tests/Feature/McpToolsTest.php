<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Mcp\Servers\OfficeServer;
use App\Mcp\Tools\HandOff;
use App\Mcp\Tools\OfficeOverview;
use App\Mcp\Tools\ReportStatus;
use App\Models\Agent;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_report_status_creates_the_agent_and_keeps_its_desk(): void
    {
        OfficeServer::tool(ReportStatus::class, [
            'agent' => 'mail-writer',
            'status' => 'working',
            'project' => 'Internal',
            'role' => 'Mail writer',
            'message' => 'Writing 6 sales emails',
        ])->assertOk()->assertSee('Mail writer');

        $agent = Agent::firstWhere('key', 'mcp:mail-writer');
        $this->assertSame('Internal', $agent->project->name);
        $this->assertSame(Status::Working, $agent->status);

        $agent->update(['name' => 'Femke']);

        OfficeServer::tool(ReportStatus::class, [
            'agent' => 'mail-writer',
            'status' => 'waiting',
            'message' => '6 drafts ready',
        ])->assertOk()->assertSee('Femke');

        $agent->refresh();
        $this->assertSame('Femke', $agent->name);
        $this->assertSame('Mail writer', $agent->role);
        $this->assertSame(Status::Waiting, $agent->status);
        $this->assertSame(1, Agent::count());
        Event::assertDispatched(OfficeChanged::class);
    }

    public function test_report_status_rejects_unknown_statuses(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'x', 'status' => 'dancing'])->assertHasErrors();
    }

    public function test_hand_off_shows_the_handover_and_wakes_the_receiver(): void
    {
        OfficeServer::tool(HandOff::class, [
            'from' => 'researcher',
            'to' => 'designer',
            'what' => '4 new findings',
            'project' => 'Internal',
        ])->assertOk()->assertSee('4 new findings');

        $from = Agent::firstWhere('key', 'mcp:researcher');
        $to = Agent::firstWhere('key', 'mcp:designer');

        $this->assertDatabaseHas('agent_events', ['type' => 'handoff', 'agent_id' => $from->id, 'to_agent_id' => $to->id]);
        $this->assertSame(Status::Working, $to->status);
        $this->assertSame($from->project_id, $to->project_id);
    }

    public function test_parallel_subagents_sit_next_to_the_agent_that_started_them(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'atlas', 'status' => 'working', 'project' => 'Atlas']);

        foreach ([1, 2] as $n) {
            OfficeServer::tool(HandOff::class, ['from' => 'atlas', 'to' => "atlas-builder-{$n}", 'what' => "Track {$n}"])->assertOk();
        }

        OfficeServer::tool(ReportStatus::class, ['agent' => 'atlas-reviewer', 'status' => 'working'])->assertOk();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'atlas-onderzoeker', 'status' => 'working'])->assertOk();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'tester-a', 'status' => 'working', 'parent' => 'atlas'])->assertOk();

        $parent = Agent::firstWhere('key', 'mcp:atlas');

        foreach (['atlas-builder-1', 'atlas-builder-2', 'atlas-reviewer', 'atlas-onderzoeker', 'tester-a'] as $name) {
            $sub = Agent::firstWhere('key', "mcp:{$name}");
            $this->assertSame($parent->id, $sub->parent_id, $name);
            $this->assertSame($parent->project_id, $sub->project_id, $name);
        }

        $this->assertSame(Status::Working, Agent::firstWhere('key', 'mcp:atlas-builder-2')->status);
        $this->assertNull(Agent::firstWhere('key', 'mcp:atlas')->parent_id);
    }

    public function test_overview_lists_who_is_waiting(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'inbox-helper', 'status' => 'waiting', 'message' => 'List ready']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'morning', 'status' => 'sleeping']);

        OfficeServer::tool(OfficeOverview::class, ['status' => 'waiting'])
            ->assertOk()
            ->assertSee('List ready')
            ->assertDontSee('Morning');
    }

    public function test_each_new_agent_gets_its_own_name(): void
    {
        foreach (['a', 'b', 'c'] as $agent) {
            OfficeServer::tool(ReportStatus::class, ['agent' => $agent, 'status' => 'idle']);
        }

        $this->assertSame(3, Agent::query()->distinct()->count('name'));
    }
}
