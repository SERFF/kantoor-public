<?php

namespace Tests\Feature;

use App\Events\UsageChanged;
use App\Models\HookToken;
use App\Models\UsageLimit;
use App\Models\UsageSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsageWindowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = HookToken::issue('MacBook');
        Event::fake([UsageChanged::class]);
        $this->travelTo('2026-10-26 10:45:00');
    }

    private function report(array $rateLimits, array $extra = [])
    {
        return $this->withToken($this->token)->postJson('/api/hooks/usage', ['source' => 'desktop', 'rate_limits' => $rateLimits] + $extra);
    }

    private function at(string $time): int
    {
        return Carbon::parse("2026-10-26 {$time}")->getTimestamp();
    }

    public function test_each_window_keeps_its_own_measurement_moment(): void
    {
        $this->report(['five_hour' => ['used_percentage' => 3], 'seven_day' => ['used_percentage' => 9]], ['measured_at' => $this->at('09:13')]);

        $this->report([
            'five_hour' => ['used_percentage' => 12, 'measured_at' => $this->at('10:41')],
            'seven_day' => ['used_percentage' => 9, 'measured_at' => $this->at('09:13')],
        ])->assertOk()
            ->assertJsonPath('usage.five_hour.used', 12)
            ->assertJsonPath('usage.five_hour.measured_at', Carbon::parse('2026-10-26 10:41')->toIso8601String())
            ->assertJsonPath('usage.week.used', 9)
            ->assertJsonPath('usage.measured_at', Carbon::parse('2026-10-26 09:13')->toIso8601String());
    }

    public function test_an_old_five_hour_reading_does_not_block_a_new_week_reading(): void
    {
        $this->report(['five_hour' => ['used_percentage' => 12, 'measured_at' => $this->at('10:41')]]);

        $this->report([
            'five_hour' => ['used_percentage' => 3, 'measured_at' => $this->at('09:13')],
            'seven_day' => ['used_percentage' => 11, 'measured_at' => $this->at('10:30')],
        ])->assertOk()
            ->assertJsonPath('usage.five_hour.used', 12)
            ->assertJsonPath('usage.week.used', 11);

        $this->assertSame(11.0, UsageLimit::current()->seven_day_used);
    }

    public function test_readings_of_both_windows_at_the_same_moment_share_one_sample(): void
    {
        $this->report(['five_hour' => ['used_percentage' => 12], 'seven_day' => ['used_percentage' => 9]], [
            'measured_at' => $this->at('10:41'),
            'history' => [
                ['measured_at' => $this->at('10:31'), 'five_hour' => 11],
                ['measured_at' => $this->at('10:31'), 'seven_day' => 9],
            ],
        ])->assertOk();

        $this->assertSame(2, UsageSample::count());
        $sample = UsageSample::query()->orderBy('measured_at')->first();
        $this->assertSame(11.0, $sample->five_hour_used);
        $this->assertSame(9.0, $sample->seven_day_used);
    }

    public function test_window_measurements_are_validated(): void
    {
        $this->report(['five_hour' => ['used_percentage' => 12, 'measured_at' => 'yesterday']])->assertUnprocessable();
    }
}
