<?php

namespace Tests\Feature;

use App\Events\OfficeChanged;
use App\Models\TvScreen;
use App\Models\User;
use App\Office\Office;
use App\Office\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OfficeChanged::class]);
    }

    public function test_logged_in_user_sees_the_tv_view(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/tv')->assertOk()->assertSee('css/tv.css', false)->assertSee('"tv":true', false);
        $this->get('/')->assertOk()->assertSee('Tv-weergave');
    }

    public function test_tv_view_needs_a_login_or_a_link(): void
    {
        $this->get('/tv')->assertRedirect('/login');
    }

    public function test_tv_link_shows_the_office_without_login(): void
    {
        $office = app(Office::class);
        $agent = $office->agent('mcp:research', ['project' => 'Intern', 'role' => 'Onderzoeker']);
        $office->report($agent, Status::Working, 'Speurt websites af');
        $token = TvScreen::issue('Woonkamer');

        $this->get("/tv/{$token}")->assertOk()->assertSee('"authUrl"', false);
        $this->getJson("/tv/{$token}/state")->assertOk()->assertJsonCount(1, 'agents');
        $this->assertNotNull(TvScreen::query()->first()->last_seen_at);
    }

    public function test_unknown_or_revoked_link_is_not_found(): void
    {
        $token = TvScreen::issue('Woonkamer');

        $this->artisan('kantoor:tv-link', ['name' => 'Woonkamer', '--revoke' => true])->assertSuccessful();

        $this->get("/tv/{$token}")->assertNotFound();
        $this->getJson("/tv/{$token}/state")->assertNotFound();
        $this->getJson('/tv/tv_'.str_repeat('a', 40).'/state')->assertNotFound();
    }

    public function test_tv_link_may_only_listen_to_the_office_channel(): void
    {
        config(['broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret']);
        $token = TvScreen::issue('Woonkamer');

        $this->postJson("/tv/{$token}/broadcasting/auth", ['socket_id' => '123.456', 'channel_name' => 'private-office'])
            ->assertOk()
            ->assertExactJson(['auth' => 'key:'.hash_hmac('sha256', '123.456:private-office', 'secret')]);

        $this->postJson("/tv/{$token}/broadcasting/auth", ['socket_id' => '123.456', 'channel_name' => 'private-other'])
            ->assertUnprocessable();
    }

    public function test_command_prints_a_working_link(): void
    {
        $this->artisan('kantoor:tv-link', ['name' => 'Woonkamer'])
            ->expectsOutputToContain('/tv/tv_')
            ->assertSuccessful();

        $this->assertSame(1, TvScreen::query()->count());
    }
}
