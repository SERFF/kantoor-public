<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Mcp\Servers\OfficeServer;
use App\Mcp\Tools\DaySummary;
use App\Models\LogEntry;
use App\Models\User;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LogbookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_finished_work_lands_in_the_logbook_once(): void
    {
        $office = app(Office::class);
        $agent = $office->agent('mcp:webshop', ['project' => 'Webshop', 'role' => 'Developer']);

        $office->report($agent, Status::Working, 'Building PR 41');
        $office->report($agent, Status::Done, 'PR 41 ready for review');
        $office->report($agent, Status::Done);

        $this->assertSame(1, LogEntry::count());
        $this->assertDatabaseHas('log_entries', [
            'agent_name' => $agent->name,
            'agent_role' => 'Developer',
            'project_id' => $agent->project_id,
            'message' => 'PR 41 ready for review',
        ]);
    }

    public function test_logbook_survives_the_agent_being_forgotten(): void
    {
        $office = app(Office::class);
        $agent = $office->agent('cc:session', ['source' => 'claude_code', 'project' => 'Atlas']);
        $office->report($agent, Status::Done, 'Fixed the editor');

        $agent->delete();

        $this->assertDatabaseHas('log_entries', ['message' => 'Fixed the editor', 'agent_id' => null]);
    }

    public function test_state_has_today_and_the_star_of_the_week(): void
    {
        $office = app(Office::class);
        $busy = $office->agent('mcp:busy', ['project' => 'Webshop']);
        $calm = $office->agent('mcp:calm', ['project' => 'Customer portal']);

        foreach (['One', 'Two', 'Three'] as $message) {
            $office->report($busy, Status::Working);
            $office->report($busy, Status::Done, $message);
        }
        $office->report($calm, Status::Done, 'Form is live');

        $this->actingAs(User::factory()->create());

        $this->getJson('/office/state')
            ->assertOk()
            ->assertJsonPath('today.done', 4)
            ->assertJsonPath('today.projects.0.name', 'Webshop')
            ->assertJsonPath('today.projects.0.count', 3)
            ->assertJsonPath('star.name', $busy->name)
            ->assertJsonPath('star.count', 3);
    }

    public function test_office_page_links_to_the_logbook_and_shows_today(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk()->assertSee('Delivered today')->assertSee('/logbook');
    }

    public function test_no_star_without_enough_work(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:a'), Status::Done, 'One thing');

        $this->actingAs(User::factory()->create());

        $this->getJson('/office/state')->assertJsonPath('star', null);
    }

    public function test_logbook_page_filters_on_project_and_period(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:j', ['project' => 'Webshop']), Status::Done, 'Webshop delivery');
        $office->report($office->agent('mcp:k', ['project' => 'Customer portal']), Status::Done, 'Portal delivery');
        LogEntry::query()->create(['agent_name' => 'Old', 'message' => 'From long ago', 'created_at' => now()->subDays(60)]);

        $this->get('/logbook')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->get('/logbook')->assertOk()->assertSee('Webshop delivery')->assertSee('Portal delivery')->assertDontSee('From long ago');
        $this->get('/logbook?project=webshop')->assertOk()->assertSee('Webshop delivery')->assertDontSee('Portal delivery');
        $this->get('/logbook?days=90')->assertOk()->assertSee('From long ago');
    }

    public function test_day_summary_tool_lists_delivered_and_open_work(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:j', ['project' => 'Webshop']), Status::Done, 'Set up the mail server');
        $office->report($office->agent('mcp:w', ['project' => 'Backups']), Status::Waiting, 'Waiting for DNS');

        OfficeServer::tool(DaySummary::class, [])
            ->assertOk()
            ->assertSee('Delivered on')
            ->assertSee('Set up the mail server')
            ->assertSee('Still open')
            ->assertSee('Waiting for DNS');

        OfficeServer::tool(DaySummary::class, ['project' => 'backups'])
            ->assertOk()
            ->assertDontSee('Set up the mail server')
            ->assertSee('Waiting for DNS');

        OfficeServer::tool(DaySummary::class, ['project' => 'Unknown'])->assertSee('not known');
    }

    public function test_old_log_entries_are_pruned_after_a_year(): void
    {
        LogEntry::query()->create(['agent_name' => 'Old', 'created_at' => now()->subDays(400)]);
        LogEntry::query()->create(['agent_name' => 'Recent', 'created_at' => now()->subDays(100)]);

        $this->artisan('kantoor:check')->assertSuccessful();

        $this->assertDatabaseMissing('log_entries', ['agent_name' => 'Old']);
        $this->assertDatabaseHas('log_entries', ['agent_name' => 'Recent']);
    }
}
