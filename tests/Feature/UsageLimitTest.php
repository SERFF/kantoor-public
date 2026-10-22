<?php

namespace Tests\Feature;

use App\Events\UsageChanged;
use App\Models\HookToken;
use App\Models\UsageLimit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function report(array $rateLimits, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/hooks/usage', ['rate_limits' => $rateLimits]);
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
            ->assertJsonPath('usage.five_hour.used', 24);

        $limit = UsageLimit::current();
        $this->assertSame(41.2, $limit->seven_day_used);
        $this->assertSame('2026-10-24 07:00:00', $limit->seven_day_resets_at->utc()->toDateTimeString());
        $this->assertSame(1792663200, $limit->five_hour_resets_at->getTimestamp());

        Event::assertDispatched(UsageChanged::class, fn (UsageChanged $event) => $event->broadcastWith()['usage']['week']['used'] === 41);
    }

    public function test_an_unchanged_reading_only_refreshes_the_timestamp(): void
    {
        $this->travelTo('2026-10-22 10:00:00');
        $this->report(['seven_day' => ['used_percentage' => 41, 'resets_at' => 1792743600]])->assertOk();

        $this->travelTo('2026-10-22 10:05:00');
        $this->report(['seven_day' => ['used_percentage' => 41, 'resets_at' => 1792743600]])->assertOk();

        Event::assertDispatchedTimes(UsageChanged::class, 1);
        $this->assertSame('2026-10-22 10:05:00', UsageLimit::current()->reported_at->toDateTimeString());
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
        $this->report(['seven_day' => ['used_percentage' => 140]])->assertOk()->assertJsonPath('usage.week.used', 100);
    }

    public function test_the_office_state_contains_the_usage(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/office/state')->assertOk()->assertJsonPath('usage', null);

        $this->report(['seven_day' => ['used_percentage' => 62]]);

        $this->getJson('/office/state')->assertOk()->assertJsonPath('usage.week.used', 62);
    }
}
