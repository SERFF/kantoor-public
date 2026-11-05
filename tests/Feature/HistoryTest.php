<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Mcp\Servers\OfficeServer;
use App\Mcp\Tools\GetAnswer;
use App\Mcp\Tools\HandOff;
use App\Mcp\Tools\ReportStatus;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\User;
use App\Office\History;
use App\Office\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_history_of_an_agent_shows_statuses_questions_and_answers(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'message' => 'Investigating a bug', 'project' => 'Webshop']);
        $this->travel(1)->minutes();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Which branch?']);
        app(Office::class)->answer(AgentQuestion::first(), 'develop');
        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop']);
        $this->travel(1)->minutes();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Merge?']);
        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop', 'answered_in_chat' => true]);
        $this->travel(1)->minutes();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'done', 'message' => 'PR ready']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'monitor', 'status' => 'working', 'message' => 'Something else']);

        $agent = Agent::firstWhere('key', 'mcp:webshop');

        $this->actingAs(User::factory()->create());
        $response = $this->getJson("/office/history/agents/{$agent->id}")->assertOk();

        $items = collect($response->json('items'));
        $this->assertStringContainsString('PR ready', $items->first()['text']);
        $this->assertSame(1, $response->json('delivered'));
        $this->assertNull($response->json('next'));
        $this->assertFalse($items->contains(fn ($item) => str_contains($item['text'], 'Something else')));
        $this->assertFalse($items->contains('type', 'answer'));
        $this->assertFalse($items->contains('status', 'waiting'));

        $questions = $items->where('type', 'question')->keyBy('text');
        $this->assertSame('answered', $questions['Which branch?']['status']);
        $this->assertSame('develop', $questions['Which branch?']['answer']);
        $this->assertSame('in_chat', $questions['Merge?']['status']);
        $this->assertNull($questions['Merge?']['answer']);
    }

    public function test_history_of_a_project_includes_all_its_agents_and_handoffs(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'message' => 'Start', 'project' => 'Webshop']);
        OfficeServer::tool(HandOff::class, ['from' => 'webshop', 'to' => 'webshop-builder', 'what' => 'fix']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop-builder', 'status' => 'working', 'message' => 'Building']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'loose-task', 'status' => 'working', 'message' => 'No project']);

        $agent = Agent::firstWhere('key', 'mcp:webshop');

        $this->actingAs(User::factory()->create());
        $items = collect($this->getJson("/office/history/projects/{$agent->project_id}")->assertOk()->json('items'));

        $this->assertTrue($items->contains('type', 'handoff'));
        $this->assertTrue($items->contains(fn ($item) => $item['agent']['name'] === Agent::firstWhere('key', 'mcp:webshop-builder')->name));
        $this->assertFalse($items->contains(fn ($item) => str_contains($item['text'], 'No project')));

        $none = collect($this->getJson('/office/history/projects/none')->assertOk()->json('items'));
        $this->assertTrue($none->contains(fn ($item) => str_contains($item['text'], 'No project')));
    }

    public function test_history_is_paginated(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'message' => 'Start']);
        $agent = Agent::first();

        foreach (range(1, History::PER_PAGE + 5) as $i) {
            $this->travel(1)->minutes();
            OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => $i % 2 ? 'done' : 'working', 'message' => "Step {$i}"]);
        }

        $this->actingAs(User::factory()->create());
        $first = $this->getJson("/office/history/agents/{$agent->id}")->assertOk();
        $this->assertCount(History::PER_PAGE, $first->json('items'));
        $this->assertNotNull($first->json('next'));

        $second = $this->getJson("/office/history/agents/{$agent->id}?before=".urlencode($first->json('next')))->assertOk();
        $this->assertSame(collect($first->json('items'))->last()['key'], $second->json('items.0.key'));
        $this->assertCount(7, $second->json('items'));
        $this->assertNull($second->json('next'));
    }

    public function test_history_requires_login(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working']);

        $this->getJson('/office/history/agents/'.Agent::first()->id)->assertUnauthorized();
    }

    public function test_hidden_waiting_reports_do_not_cut_the_history_short(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'message' => 'Old work']);
        $this->travel(1)->minutes();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Which branch?']);

        foreach (range(1, History::PER_PAGE + 5) as $i) {
            $this->travel(1)->minutes();
            OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'message' => 'Which branch?']);
        }

        $agent = Agent::first();
        $this->actingAs(User::factory()->create());

        $items = collect();
        $next = null;

        foreach (range(1, 5) as $page) {
            $response = $this->getJson("/office/history/agents/{$agent->id}".($next ? '?before='.urlencode($next) : ''))->assertOk();
            $items = $items->concat($response->json('items'));
            $next = $response->json('next');

            if (! $next) {
                break;
            }
        }

        $this->assertNull($next);
        $this->assertTrue($items->contains(fn ($item) => str_contains($item['text'], 'Old work')));
        $this->assertSame(1, $items->where('type', 'question')->unique('key')->count());
    }

    public function test_deliveries_of_a_project_are_counted_by_project(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'done', 'message' => 'Done', 'project' => 'Webshop']);
        $agent = Agent::first();
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'working', 'project' => 'Other project']);

        $this->actingAs(User::factory()->create());

        $this->getJson("/office/history/projects/{$agent->project_id}")->assertOk()->assertJsonPath('delivered', 1);
        $this->getJson("/office/history/agents/{$agent->id}")->assertOk()->assertJsonPath('delivered', 1);
    }

    public function test_history_names_its_subject_and_counts_questions(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Which branch?', 'project' => 'Webshop', 'role' => 'Builder']);
        $this->travel(10)->minutes();
        app(Office::class)->answer(AgentQuestion::first(), 'develop');
        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop']);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Merge?']);
        $this->travel(20)->minutes();
        OfficeServer::tool(GetAnswer::class, ['agent' => 'webshop', 'answered_in_chat' => true]);
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'waiting', 'question' => 'Deploy?']);

        $agent = Agent::first();
        $this->actingAs(User::factory()->create());

        $this->getJson("/office/history/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('subject.name', $agent->name)
            ->assertJsonPath('subject.subtitle', 'Builder · Webshop')
            ->assertJsonPath('questions.asked', 3)
            ->assertJsonPath('questions.answered', 1)
            ->assertJsonPath('questions.in_chat', 1)
            ->assertJsonPath('questions.open', 1)
            ->assertJsonPath('questions.median_minutes', 15);

        $this->getJson("/office/history/projects/{$agent->project_id}")
            ->assertOk()
            ->assertJsonPath('subject.name', 'Webshop')
            ->assertJsonPath('questions.asked', 3);
    }

    public function test_logbook_links_to_the_history(): void
    {
        OfficeServer::tool(ReportStatus::class, ['agent' => 'webshop', 'status' => 'done', 'message' => 'PR ready', 'project' => 'Webshop']);
        $agent = Agent::first();

        $this->actingAs(User::factory()->create());

        $this->get('/logbook')->assertOk()->assertSee('history=agent-'.$agent->id, false);
        $this->get('/logbook?project='.$agent->project->slug)->assertOk()->assertSee('history=project-'.$agent->project_id, false);
        $this->getJson('/office/state')->assertOk()->assertJsonPath('today.projects.0.items.0.agent_id', $agent->id);
    }
}
