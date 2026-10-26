<?php

namespace Tests\Feature;

use App\Events\UsageChanged;
use App\Models\HookToken;
use App\Models\UsageSample;
use App\Office\UsageProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsageProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kantoor.timezone' => 'Europe/Amsterdam']);
        Event::fake([UsageChanged::class]);
    }

    private function sample(string $at, float $week): void
    {
        UsageSample::remember(Carbon::parse($at, 'Europe/Amsterdam'), null, $week);
    }

    public function test_buckets_follow_the_local_clock(): void
    {
        $projection = UsageProjection::make();

        $this->assertSame('night', $projection->bucket(Carbon::parse('2026-10-26 05:00', 'Europe/Amsterdam')->getTimestamp()));
        $this->assertSame('work', $projection->bucket(Carbon::parse('2026-10-26 10:00', 'Europe/Amsterdam')->getTimestamp()));
        $this->assertSame('evening', $projection->bucket(Carbon::parse('2026-10-26 20:00', 'Europe/Amsterdam')->getTimestamp()));
        $this->assertSame('weekend', $projection->bucket(Carbon::parse('2026-10-31 14:00', 'Europe/Amsterdam')->getTimestamp()));
    }

    public function test_without_history_it_projects_from_the_priors(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Europe/Amsterdam'));

        $result = UsageProjection::make()->week(9, Carbon::parse('2026-11-01 04:00', 'Europe/Amsterdam'));

        $this->assertGreaterThan(9, $result['projected']);
        $this->assertLessThan(100, $result['projected']);
        $this->assertNull($result['empty_at']);
    }

    public function test_no_projection_without_a_reset_or_after_it(): void
    {
        $this->travelTo('2026-10-26 10:00');

        $this->assertNull(UsageProjection::make()->week(9, null));
        $this->assertNull(UsageProjection::make()->week(9, Carbon::parse('2026-10-26 09:00')));
        $this->assertNull(UsageProjection::make()->week(null, Carbon::parse('2026-11-01 03:00')));
    }

    public function test_a_heavy_history_raises_the_projection(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Europe/Amsterdam'));
        $reset = Carbon::parse('2026-11-01 04:00', 'Europe/Amsterdam');
        $quiet = UsageProjection::make()->week(9, $reset)['projected'];

        foreach (range(2, 21) as $daysAgo) {
            $day = Carbon::parse('2026-10-26', 'Europe/Amsterdam')->subDays($daysAgo);
            if ($day->isWeekend()) {
                continue;
            }
            $this->sample($day->copy()->setTime(9, 0)->toDateTimeString(), 10);
            $this->sample($day->copy()->setTime(17, 0)->toDateTimeString(), 26);
        }

        $busy = UsageProjection::make()->week(9, $reset)['projected'];

        $this->assertGreaterThan($quiet, $busy);
    }

    public function test_a_fast_burn_predicts_when_the_tank_runs_empty(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 12:00', 'Europe/Amsterdam'));
        $this->sample('2026-10-29 09:00', 60);
        $this->sample('2026-10-29 12:00', 84);

        $result = UsageProjection::make()->week(84, Carbon::parse('2026-11-01 04:00', 'Europe/Amsterdam'));

        $this->assertSame(100, $result['projected']);
        $this->assertNotNull($result['empty_at']);
        $this->assertTrue(Carbon::parse($result['empty_at'])->between(now(), now()->addHours(3)));
    }

    public function test_the_hook_stores_history_and_reports_the_projection(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 10:00', 'Europe/Amsterdam'));
        $token = HookToken::issue('MacBook');

        $this->withToken($token)->postJson('/api/hooks/usage', [
            'source' => 'desktop',
            'measured_at' => now()->subMinutes(5)->getTimestamp(),
            'rate_limits' => ['seven_day' => ['used_percentage' => 9, 'resets_at' => Carbon::parse('2026-11-01 04:00', 'Europe/Amsterdam')->getTimestamp()]],
            'history' => [
                ['measured_at' => now()->subDays(3)->getTimestamp(), 'five_hour' => 2, 'seven_day' => 90],
                ['measured_at' => now()->subDays(40)->getTimestamp(), 'seven_day' => 10],
                ['measured_at' => now()->subMinutes(5)->getTimestamp(), 'seven_day' => 9],
            ],
        ])->assertOk()
            ->assertJsonPath('usage.week.empty_at', null)
            ->assertJsonStructure(['usage' => ['week' => ['projected']]]);

        $this->assertSame(2, UsageSample::count());
    }

    public function test_history_is_validated(): void
    {
        $this->withToken(HookToken::issue('MacBook'))->postJson('/api/hooks/usage', [
            'rate_limits' => ['seven_day' => ['used_percentage' => 9]],
            'history' => [['seven_day' => 9]],
        ])->assertUnprocessable();
    }
}
