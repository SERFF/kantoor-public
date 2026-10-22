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
        $agent = $office->agent('mcp:webshop', ['project' => 'Webshop', 'role' => 'Ontwikkelaar']);

        $office->report($agent, Status::Working, 'Bouwt PR 41');
        $office->report($agent, Status::Done, 'PR 41 klaar voor review');
        $office->report($agent, Status::Done);

        $this->assertSame(1, LogEntry::count());
        $this->assertDatabaseHas('log_entries', [
            'agent_name' => $agent->name,
            'agent_role' => 'Ontwikkelaar',
            'project_id' => $agent->project_id,
            'message' => 'PR 41 klaar voor review',
        ]);
    }

    public function test_logbook_survives_the_agent_being_forgotten(): void
    {
        $office = app(Office::class);
        $agent = $office->agent('cc:sessie', ['source' => 'claude_code', 'project' => 'Lorge']);
        $office->report($agent, Status::Done, 'Editor gefixt');

        $agent->delete();

        $this->assertDatabaseHas('log_entries', ['message' => 'Editor gefixt', 'agent_id' => null]);
    }

    public function test_state_has_today_and_the_star_of_the_week(): void
    {
        $office = app(Office::class);
        $busy = $office->agent('mcp:busy', ['project' => 'Webshop']);
        $calm = $office->agent('mcp:calm', ['project' => 'Klantportaal']);

        foreach (['Een', 'Twee', 'Drie'] as $message) {
            $office->report($busy, Status::Working);
            $office->report($busy, Status::Done, $message);
        }
        $office->report($calm, Status::Done, 'Formulier live');

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

        $this->get('/')->assertOk()->assertSee('Vandaag opgeleverd')->assertSee('/logboek');
    }

    public function test_no_star_without_enough_work(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:a'), Status::Done, 'Eén ding');

        $this->actingAs(User::factory()->create());

        $this->getJson('/office/state')->assertJsonPath('star', null);
    }

    public function test_logbook_page_filters_on_project_and_period(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:j', ['project' => 'Webshop']), Status::Done, 'Webshop-oplevering');
        $office->report($office->agent('mcp:k', ['project' => 'Klantportaal']), Status::Done, 'Klantportaal-oplevering');
        LogEntry::query()->create(['agent_name' => 'Oud', 'message' => 'Van lang geleden', 'created_at' => now()->subDays(60)]);

        $this->get('/logboek')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->get('/logboek')->assertOk()->assertSee('Webshop-oplevering')->assertSee('Klantportaal-oplevering')->assertDontSee('Van lang geleden');
        $this->get('/logboek?project=webshop')->assertOk()->assertSee('Webshop-oplevering')->assertDontSee('Klantportaal-oplevering');
        $this->get('/logboek?dagen=90')->assertOk()->assertSee('Van lang geleden');
    }

    public function test_day_summary_tool_lists_delivered_and_open_work(): void
    {
        $office = app(Office::class);
        $office->report($office->agent('mcp:j', ['project' => 'Webshop']), Status::Done, 'Mailserver ingesteld');
        $office->report($office->agent('mcp:w', ['project' => 'Back-ups']), Status::Waiting, 'Wacht op DNS');

        OfficeServer::tool(DaySummary::class, [])
            ->assertOk()
            ->assertSee('Opgeleverd op')
            ->assertSee('Mailserver ingesteld')
            ->assertSee('Nog open')
            ->assertSee('Wacht op DNS');

        OfficeServer::tool(DaySummary::class, ['project' => 'back-ups'])
            ->assertOk()
            ->assertDontSee('Mailserver ingesteld')
            ->assertSee('Wacht op DNS');

        OfficeServer::tool(DaySummary::class, ['project' => 'Onbekend'])->assertSee('ken ik niet');
    }

    public function test_old_log_entries_are_pruned_after_a_year(): void
    {
        LogEntry::query()->create(['agent_name' => 'Oud', 'created_at' => now()->subDays(400)]);
        LogEntry::query()->create(['agent_name' => 'Recent', 'created_at' => now()->subDays(100)]);

        $this->artisan('kantoor:check')->assertSuccessful();

        $this->assertDatabaseMissing('log_entries', ['agent_name' => 'Oud']);
        $this->assertDatabaseHas('log_entries', ['agent_name' => 'Recent']);
    }
}
