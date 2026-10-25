<?php

namespace Tests\Feature;

use App\Mcp\Servers\OfficeServer;
use App\Mcp\Tools\WeekSummary;
use App\Models\LogEntry;
use App\Models\OfficeDay;
use App\Models\Project;
use App\Models\User;
use App\Office\WeekRecap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeekRecapTest extends TestCase
{
    use RefreshDatabase;

    private Project $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00', 'Europe/Amsterdam'));
        $this->acme = Project::resolve('Acme');

        foreach (['2026-10-20 10:00', '2026-10-21 11:00', '2026-10-21 14:00', '2026-10-13 09:00'] as $i => $at) {
            LogEntry::query()->create([
                'project_id' => $this->acme->id,
                'agent_name' => $i < 3 ? 'Noor' : 'Bas',
                'avatar' => 1,
                'message' => 'Iets af',
                'created_at' => CarbonImmutable::parse($at, 'Europe/Amsterdam')->utc(),
            ]);
        }

        OfficeDay::query()->create(['date' => '2026-10-20', 'project_id' => $this->acme->id, 'sessions' => 2, 'prompts' => 7, 'edits' => 10, 'lines_added' => 120, 'lines_removed' => 30, 'approvals' => 1]);
        OfficeDay::query()->create(['date' => '2026-10-21', 'project_id' => 0, 'sessions' => 1, 'prompts' => 3, 'edits' => 2, 'lines_added' => 5, 'lines_removed' => 1]);
        OfficeDay::query()->create(['date' => '2026-10-14', 'project_id' => $this->acme->id, 'prompts' => 99]);
    }

    public function test_it_counts_the_week_from_monday(): void
    {
        $week = app(WeekRecap::class)->week();

        $this->assertSame(['2026-10-19', '2026-10-25', 43], [$week['from'], $week['until'], $week['week']]);
        $this->assertSame([3, 3, 10, 12, 125, 31, 1], [$week['done'], $week['sessions'], $week['prompts'], $week['edits'], $week['lines_added'], $week['lines_removed'], $week['approvals']]);
        $this->assertSame('Wednesday', $week['busiest']['day']);
        $this->assertSame(['name' => 'Noor', 'avatar' => 1, 'count' => 3], $week['star']);
        $this->assertSame(['Acme', 'No project'], array_column($week['projects'], 'name'));
    }

    public function test_on_monday_morning_the_office_shows_last_week(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Amsterdam'));
        $this->assertTrue(app(WeekRecap::class)->current()['previous']);
        $this->assertSame('2026-10-19', app(WeekRecap::class)->current()['from']);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 13:00', 'Europe/Amsterdam'));
        $this->assertFalse(app(WeekRecap::class)->current()['previous']);
        $this->assertSame(0, app(WeekRecap::class)->current()['done']);
    }

    public function test_the_office_state_contains_the_week(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/office/state')
            ->assertOk()
            ->assertJsonPath('week.done', 3)
            ->assertJsonPath('week.previous', false);
    }

    public function test_week_summary_tool(): void
    {
        OfficeServer::tool(WeekSummary::class, [])
            ->assertOk()
            ->assertSee('Week 43')
            ->assertSee('Delivered: 3')
            ->assertSee('Busiest day: Wednesday');

        OfficeServer::tool(WeekSummary::class, ['week' => 'previous'])->assertSee('Delivered: 1');
        OfficeServer::tool(WeekSummary::class, ['week' => 'gisteren'])->assertHasErrors();
    }
}
