<?php

namespace Tests\Feature;

use App\Events\UsageChanged;
use App\Models\HookToken;
use App\Models\UsageLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = HookToken::issue('MacBook');
        Event::fake([UsageChanged::class]);
    }

    private function report(array $rateLimits, ?string $token = null, array $extra = [])
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/hooks/usage', ['rate_limits' => $rateLimits] + $extra);
    }

    private function desktop(array $rateLimits, string $measuredAt)
    {
        return $this->report($rateLimits, null, ['source' => 'desktop', 'measured_at' => Carbon::parse($measuredAt)->getTimestamp()]);
    }

    public function test_usage_requires_a_claude_code_hook_token(): void
    {
        $this->postJson('/api/hooks/usage', ['rate_limits' => []])->assertUnauthorized();
        $this->report(['seven_day' => ['used_percentage' => 10]], HookToken::issue('Monitoring', HookToken::ALERT))->assertUnauthorized();

        $this->assertDatabaseCount('usage_limits', 0);
    }

    public function test_it_stores_both_windows_and_broadcasts(): void
    {
        $this->travelTo('2026-10-22 10:00:00');

        $this->report([
            'five_hour' => ['used_percentage' => 23.5, 'resets_at' => 1792663200],
            'seven_day' => ['used_percentage' => 41.2, 'resets_at' => '2026-10-24T07:00:00Z'],
        ])->assertOk()
            ->assertJsonPath('usage.week.used', 41)
            ->assertJsonPath('usage.week.estimated', false)
            ->assertJsonPath('usage.five_hour.used', 24);

        $limit = UsageLimit::current();
        $this->assertSame(41.2, $limit->seven_day_used);
        $this->assertSame('2026-10-24 07:00:00', $limit->seven_day_resets_at->utc()->toDateTimeString());
        $this->assertSame(1792663200, $limit->five_hour_resets_at->getTimestamp());
        $this->assertSame('2026-10-22 10:00:00', $limit->measured_at->toDateTimeString());

        Event::assertDispatched(UsageChanged::class, fn (UsageChanged $event) => $event->broadcastWith()['usage']['week']['used'] === 41);
    }

    public function test_a_heartbeat_with_the_same_measurement_only_refreshes_the_timestamp(): void
    {
        $measured = Carbon::parse('2026-10-22 10:00:00')->getTimestamp();

        $this->travelTo('2026-10-22 10:00:00');
        $this->report(['seven_day' => ['used_percentage' => 41, 'resets_at' => 1792743600]], null, ['measured_at' => $measured])->assertOk();

        $this->travelTo('2026-10-22 10:10:00');
        $this->report(['seven_day' => ['used_percentage' => 41, 'resets_at' => 1792743600]], null, ['measured_at' => $measured])->assertOk();

        Event::assertDispatchedTimes(UsageChanged::class, 1);
        $this->assertSame('2026-10-22 10:10:00', UsageLimit::current()->reported_at->toDateTimeString());
    }

    public function test_a_missing_window_keeps_the_last_known_value(): void
    {
        $this->report(['seven_day' => ['used_percentage' => 41]])->assertOk();
        $this->report(['five_hour' => ['used_percentage' => 5]])->assertOk();

        $this->assertSame(41.0, UsageLimit::current()->seven_day_used);
        $this->assertSame(5.0, UsageLimit::current()->five_hour_used);
    }

    public function test_percentages_are_clamped_and_validated(): void
    {
        $this->report(['seven_day' => ['used_percentage' => 'lots']])->assertUnprocessable();
        $this->report(['seven_day' => ['used_percentage' => 10]], null, ['source' => 'elsewhere'])->assertUnprocessable();
        $this->report(['seven_day' => ['used_percentage' => 140]])->assertOk()->assertJsonPath('usage.week.used', 100);
    }

    public function test_an_older_measurement_does_not_overwrite_a_newer_one(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->desktop(['seven_day' => ['used_percentage' => 12]], '2026-10-26 09:50:00')->assertOk();
        $this->desktop(['seven_day' => ['used_percentage' => 90]], '2026-10-23 04:43:00')->assertOk()->assertJsonPath('usage.week.used', 12);

        $this->assertSame(12.0, UsageLimit::current()->seven_day_used);
        Event::assertDispatchedTimes(UsageChanged::class, 1);
    }

    public function test_a_newer_lower_measurement_wins(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->desktop(['seven_day' => ['used_percentage' => 63]], '2026-10-26 09:00:00');
        $this->desktop(['seven_day' => ['used_percentage' => 2]], '2026-10-26 09:30:00')->assertJsonPath('usage.week.used', 2);
    }

    public function test_a_measurement_from_the_future_counts_as_now(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->desktop(['seven_day' => ['used_percentage' => 5]], '2026-10-27 10:00:00')->assertOk();

        $this->assertSame('2026-10-26 10:00:00', UsageLimit::current()->measured_at->toDateTimeString());
    }

    public function test_an_implausible_reset_is_ignored(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->report(['seven_day' => ['used_percentage' => 5, 'resets_at' => '2027-01-01T00:00:00Z']])->assertOk()
            ->assertJsonPath('usage.week.resets_at', null);
    }

    public function test_an_estimated_reset_does_not_replace_a_known_one(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->report(['seven_day' => ['used_percentage' => 9, 'resets_at' => '2026-11-01T13:00:00Z']]);
        $this->desktop(['seven_day' => ['used_percentage' => 10, 'resets_at' => '2026-11-01T14:27:00Z', 'resets_estimated' => true]], '2026-10-26 10:00:00')
            ->assertJsonPath('usage.week.used', 10)
            ->assertJsonPath('usage.week.estimated', false);

        $this->assertSame('2026-11-01 13:00:00', UsageLimit::current()->seven_day_resets_at->utc()->toDateTimeString());
    }

    public function test_an_estimated_reset_is_used_when_nothing_better_is_known(): void
    {
        $this->travelTo('2026-10-26 10:00:00');

        $this->desktop(['seven_day' => ['used_percentage' => 9, 'resets_at' => '2026-11-01T14:27:00Z', 'resets_estimated' => true]], '2026-10-26 09:13:00')
            ->assertJsonPath('usage.week.estimated', true)
            ->assertJsonPath('usage.source', 'desktop');
    }

    public function test_a_known_weekly_reset_rolls_on_to_the_next_week(): void
    {
        $this->travelTo('2026-10-22 10:00:00');
        $this->report(['seven_day' => ['used_percentage' => 80, 'resets_at' => '2026-10-25T13:00:00Z']]);

        $this->travelTo('2026-10-26 10:00:00');
        $this->desktop(['seven_day' => ['used_percentage' => 9]], '2026-10-26 09:13:00')->assertOk();

        $limit = UsageLimit::current();
        $this->assertSame('2026-11-01 13:00:00', $limit->seven_day_resets_at->utc()->toDateTimeString());
        $this->assertFalse($limit->seven_day_resets_estimated);
    }

    public function test_an_expired_five_hour_reset_is_cleared(): void
    {
        $this->travelTo('2026-10-26 08:00:00');
        $this->report(['five_hour' => ['used_percentage' => 40, 'resets_at' => '2026-10-26T09:00:00Z']]);

        $this->travelTo('2026-10-26 10:00:00');
        $this->desktop(['five_hour' => ['used_percentage' => 3]], '2026-10-26 09:55:00')->assertJsonPath('usage.five_hour.resets_at', null);
    }

    public function test_the_office_state_contains_the_usage(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/office/state')->assertOk()->assertJsonPath('usage', null);

        $this->report(['seven_day' => ['used_percentage' => 62]]);

        $this->getJson('/office/state')->assertOk()->assertJsonPath('usage.week.used', 62);
    }

    public function test_the_desktop_script_can_be_downloaded(): void
    {
        $this->get('/hooks/kantoor-desktop-usage.sh')->assertOk()->assertSee('plan-usage-history.json')->assertDontSee('__KANTOOR_URL__');
    }
}
