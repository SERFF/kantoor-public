<?php

namespace Tests\Feature;

use App\Events\AlarmsChanged;
use App\Events\OfficeChanged;
use App\Models\Alarm;
use App\Models\HookToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AlertWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class, AlarmsChanged::class]);

        $this->token = HookToken::issue('Monitoring', HookToken::ALERT);
    }

    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'action' => 'new_issue',
            'action_label' => 'Nieuw issue',
            'project' => ['id' => 3, 'name' => 'Webshop', 'slug' => 'webshop'],
            'issue' => [
                'id' => 42,
                'short_id' => 'WEBSHOP-42',
                'title' => 'SQLSTATE fout voor jan@example.com',
                'culprit' => 'App\\Http\\Controllers\\PlanningController',
                'level' => 'error',
                'status' => 'unresolved',
                'times_seen' => 1,
                'url' => 'https://monitor.example.com/issues/42',
            ],
            'event' => ['message' => 'Geheime inhoud', 'user' => 'jan@example.com'],
        ], $overrides);
    }

    protected function send(array $payload, ?string $token = null)
    {
        return $this->postJson('/api/hooks/alerts/'.($token ?? $this->token), $payload, ['X-Alert-Event' => $payload['action']]);
    }

    public function test_a_new_issue_rings_the_phone_without_storing_error_content(): void
    {
        $project = Project::resolve('Webshop');

        $this->send($this->payload())->assertOk();

        $alarm = Alarm::sole();
        $this->assertSame($project->id, $alarm->project_id);
        $this->assertSame('WEBSHOP-42', $alarm->short_id);
        $this->assertSame('https://monitor.example.com/issues/42', $alarm->url);
        $this->assertTrue($alarm->isRinging());

        $stored = json_encode(Alarm::query()->first()->getAttributes());
        $this->assertStringNotContainsString('jan@example.com', $stored);
        $this->assertStringNotContainsString('SQLSTATE', $stored);
        $this->assertStringNotContainsString('PlanningController', $stored);

        Event::assertDispatched(AlarmsChanged::class, fn ($event) => $event->ringing === $alarm->id);
    }

    public function test_unknown_projects_are_not_created_as_islands(): void
    {
        $this->send($this->payload(['project' => ['name' => 'Klantsite', 'slug' => 'klantsite']]))->assertOk();

        $this->assertNull(Alarm::sole()->project_id);
        $this->assertSame('Klantsite', Alarm::sole()->project_name);
        $this->assertSame(0, Project::count());
    }

    public function test_project_match_patterns_are_used(): void
    {
        $project = Project::resolve('Webshop');
        $project->update(['match' => 'webshop*']);

        $this->send($this->payload(['project' => ['name' => 'Webshop API', 'slug' => 'webshop-api']]))->assertOk();

        $this->assertSame($project->id, Alarm::sole()->project_id);
    }

    public function test_wrong_or_claude_code_token_is_refused(): void
    {
        $this->send($this->payload(), 'kl_'.str_repeat('a', 48))->assertNotFound();

        $claudeCode = HookToken::issue('Mac');
        $this->postJson('/api/hooks/alerts/'.$claudeCode, $this->payload())->assertNotFound();

        $this->postJson('/api/hooks/claude-code', ['session_id' => 'x', 'hook_event_name' => 'Stop'], ['Authorization' => 'Bearer '.$this->token])->assertUnauthorized();

        $this->assertSame(0, Alarm::count());
    }

    public function test_the_same_issue_does_not_ring_again_while_ringing(): void
    {
        $this->send($this->payload())->assertOk();
        $this->travel(10)->minutes();
        $this->send($this->payload(['action' => 'frequency', 'issue' => ['times_seen' => 30]]))->assertOk();

        $alarm = Alarm::sole();
        $this->assertSame(30, $alarm->times_seen);
        $this->assertTrue($alarm->rang_at->lt(now()->subMinutes(9)));
        Event::assertDispatched(AlarmsChanged::class, fn ($event) => $event->ringing === null);
    }

    public function test_answering_stops_the_ringing_and_a_regression_rings_again(): void
    {
        $this->send($this->payload())->assertOk();
        $alarm = Alarm::sole();

        $this->postJson("/office/alarms/{$alarm->id}/answer")->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->postJson("/office/alarms/{$alarm->id}/answer")->assertOk();
        $this->assertFalse($alarm->fresh()->isRinging());

        $this->send($this->payload(['action' => 'regression']))->assertOk();
        $this->assertTrue($alarm->fresh()->isRinging());

        $this->postJson('/office/alarms/answer')->assertOk();
        $this->assertSame(0, Alarm::query()->ringing()->count());
    }

    public function test_ringing_stops_after_an_hour_and_shows_in_the_state(): void
    {
        $this->send($this->payload())->assertOk();
        $this->actingAs(User::factory()->create());

        $this->getJson('/office/state')
            ->assertJsonPath('alarms.0.short_id', 'WEBSHOP-42')
            ->assertJsonPath('alarms.0.label', 'Nieuwe fout')
            ->assertJsonMissingPath('alarms.0.title');

        $this->travel(61)->minutes();

        $this->getJson('/office/state')->assertJsonCount(0, 'alarms');
    }

    public function test_test_message_from_monitor_rings_too(): void
    {
        $this->send($this->payload(['action' => 'test', 'issue' => ['id' => null]]))->assertOk();

        $this->assertSame('Testmelding', Alarm::sole()->label());
    }

    public function test_unsafe_links_are_dropped(): void
    {
        $this->send($this->payload(['issue' => ['url' => 'javascript:alert(1)']]))->assertOk();

        $this->assertNull(Alarm::sole()->url);
    }

    public function test_command_prints_a_working_webhook_url(): void
    {
        $this->artisan('kantoor:alert-link', ['name' => 'Productie'])
            ->expectsOutputToContain('/api/hooks/alerts/ka_')
            ->assertSuccessful();

        $this->assertSame(1, HookToken::query()->where('kind', HookToken::ALERT)->where('name', 'Productie')->count());

        $this->artisan('kantoor:alert-link', ['name' => 'Productie', '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, HookToken::query()->where('name', 'Productie')->count());
    }
}
