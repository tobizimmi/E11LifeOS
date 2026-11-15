<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API: working time and leave.
 */
class ApiWorkTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_days_leave_and_settings(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 10)->startOfDay());
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $data = $this->getJson('/api/v1/work?from=2026-06-08&to=2026-06-14', $headers)->assertOk()->json('data');
        $this->assertCount(7, $data['period']['rows']);
        $this->assertSame(40.0, (float) $data['settings']['weekly_hours']);

        $this->putJson('/api/v1/work/days/2026-06-08', ['type' => 'work', 'starts_at' => '08:00', 'ends_at' => '17:00', 'break_minutes' => 60], $headers)->assertOk()->assertJsonPath('data.worked', 480);
        $this->putJson('/api/v1/work/days/2026-06-08', ['type' => 'work', 'starts_at' => '18:00', 'ends_at' => '17:00'], $headers)->assertStatus(422);

        $this->postJson('/api/v1/work/leave', ['from' => '2026-06-15', 'to' => '2026-06-19'], $headers)->assertCreated()->assertJsonPath('data.days', 5);
        $this->assertSame(5, WorkDay::where('type', 'vacation')->count());
        $this->deleteJson('/api/v1/work/leave', ['from' => '2026-06-15', 'to' => '2026-06-19'], $headers)->assertOk();
        $this->assertSame(0, WorkDay::where('type', 'vacation')->count());
        $this->postJson('/api/v1/work/leave', ['from' => '2026-06-13', 'to' => '2026-06-14'], $headers)->assertStatus(422);

        $this->putJson('/api/v1/work/settings', ['weekly_hours' => 30, 'work_days' => [1, 2, 3], 'vacation_days' => 24, 'state' => 'XX'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/work/settings', ['weekly_hours' => 30, 'work_days' => [1, 2, 3], 'vacation_days' => 24, 'state' => 'NW'], $headers)->assertOk()->assertJsonPath('data.state', 'NW');

        $this->deleteJson('/api/v1/work/days/2026-06-08', [], $headers)->assertOk();
        $this->assertSame(0, WorkDay::count());
    }

    public function test_the_clock_starts_and_stops_today(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/work/clock', [], $headers)->assertOk()->assertJsonPath('data.ends_at', null);
        $this->postJson('/api/v1/work/clock', [], $headers)->assertOk();
        $this->assertNotNull(WorkDay::sole()->ends_at);
    }

    public function test_data_is_personal(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other] = $this->memberWithHousehold();
        WorkDay::create(['user_id' => $other->id, 'date' => today(), 'type' => 'sick']);

        $rows = $this->getJson('/api/v1/work', $this->headersFor($user))->assertOk()->json('data.period.rows');
        $this->assertSame([], array_filter(array_column($rows, 'day')));
    }
}
