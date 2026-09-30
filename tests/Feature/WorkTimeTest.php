<?php

namespace Tests\Feature;

use App\Models\WorkDay;
use App\Models\WorkProfile;
use App\Support\Life\PublicHolidays;
use App\Support\Life\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class WorkTimeTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-05-11 09:00'));
    }

    public function test_public_holidays_depend_on_the_state(): void
    {
        $this->assertSame('2027-03-28', PublicHolidays::easterSunday(2027)->toDateString());
        $bavaria = PublicHolidays::for(2026, 'BY');
        $this->assertSame('Fronleichnam', $bavaria['2026-06-04']);
        $this->assertSame('Allerheiligen', $bavaria['2026-11-01']);
        $this->assertArrayNotHasKey('2026-10-31', $bavaria);
        $saxony = PublicHolidays::for(2026, 'SN');
        $this->assertSame('Buß- und Bettag', $saxony['2026-11-18']);
        $this->assertSame('Reformationstag', $saxony['2026-10-31']);
        $this->assertCount(10, PublicHolidays::for(2026, 'HH'));
    }

    public function test_the_week_counts_target_actual_leave_and_holidays(): void
    {
        [$user] = $this->memberWithHousehold();
        $profile = WorkProfile::for($user);
        $time = new WorkTime($profile);
        // Week with Ascension Day (Thursday 14 May 2026).
        $days = collect([
            WorkDay::factory()->create(['user_id' => $user->id, 'date' => '2026-05-11', 'starts_at' => '08:00', 'ends_at' => '17:30', 'break_minutes' => 30]),
            WorkDay::factory()->create(['user_id' => $user->id, 'date' => '2026-05-15', 'type' => 'vacation', 'starts_at' => null, 'ends_at' => null, 'break_minutes' => 0]),
        ]);

        $week = $time->period(Carbon::parse('2026-05-11'), Carbon::parse('2026-05-17'), $days);

        $this->assertSame('Christi Himmelfahrt', $week['rows'][3]['holiday']);
        $this->assertSame(0, $week['rows'][3]['target']);
        $this->assertSame(540, $week['rows'][0]['actual']);
        $this->assertSame(60, $week['rows'][0]['diff']);
        $this->assertSame(480, $week['rows'][4]['actual']);
        $this->assertSame(0, $week['rows'][1]['diff'], 'future days without entry are not missing yet');
        $this->assertSame(['entitlement' => 30.0, 'taken' => 0.0, 'planned' => 1.0, 'remaining' => 29.0, 'sick' => 0], $time->leave(2026, $days));
    }

    public function test_leave_is_booked_without_weekends_and_holidays_and_bridge_days_are_suggested(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('work.index')->set('tab', 'leave')->set('leave.from', '2026-05-11')->set('leave.to', '2026-05-17')
            ->assertSee('Kostet 4 Urlaubstage')
            ->assertSee('Christi Himmelfahrt')
            ->call('planLeave')->assertHasNoErrors();

        $this->assertSame(['2026-05-11', '2026-05-12', '2026-05-13', '2026-05-15'], WorkDay::where('user_id', $user->id)->orderBy('date')->get()->map(fn ($day) => $day->date->toDateString())->all());
        Volt::test('work.index')->set('tab', 'leave')->assertSee('Mo., 11.05.2026')->assertSee('Stornieren')->assertSee('4 Tage')
            ->call('cancelLeave', '2026-05-11', '2026-05-15');
        $this->assertSame(0, WorkDay::where('user_id', $user->id)->count());

        $tips = (new WorkTime(WorkProfile::for($user)))->bridgeDays(2026);
        $corpusChristi = collect($tips)->first(fn ($tip) => $tip['holiday'] === 'Fronleichnam');
        $this->assertSame('2026-06-05', $corpusChristi['leave'][0]->toDateString());
        $this->assertSame(4, $corpusChristi['free_days']);
    }

    public function test_clocking_in_and_out_adds_the_legal_break_and_counts_overtime(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('work.index')->call('clock');
        $this->travelTo(Carbon::parse('2026-05-11 16:30'));
        Volt::test('work.index')->call('clock')->assertSee('7:00');

        $entry = WorkDay::where('user_id', $user->id)->sole();
        $this->assertSame('09:00', substr($entry->starts_at, 0, 5));
        $this->assertSame(30, $entry->break_minutes);

        $entry->update(['starts_at' => '06:00', 'ends_at' => '17:30', 'break_minutes' => 15]);
        $this->assertSame('Mehr als 10 Stunden Arbeit – das gesetzliche Maximum.', WorkTime::breakHint($entry->fresh()));
        $entry->update(['ends_at' => '14:00']);
        $this->assertSame('Für diese Arbeitszeit sind mindestens 30 Minuten Pause vorgeschrieben.', WorkTime::breakHint($entry->fresh()));
    }

    public function test_settings_are_saved_and_entries_are_personal(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        WorkDay::factory()->create(['user_id' => $partner->id, 'date' => '2026-05-11', 'note' => 'Kundentermin Partner']);
        $this->actingAs($user);

        Volt::test('work.index')->set('tab', 'settings')->set('settings.weekly_hours', '32')->set('settings.work_days', ['1', '2', '3', '4'])
            ->set('settings.vacation_days', '28')->set('settings.carry_over_days', '2,5')->set('settings.state', 'NW')->set('settings.overtime_start', '-3:30')
            ->call('saveSettings')->assertHasNoErrors();

        $profile = WorkProfile::for($user)->fresh();
        $this->assertSame(32.0, $profile->weekly_hours);
        $this->assertSame(480, $profile->dailyTargetMinutes());
        $this->assertSame(-210, $profile->overtime_start_minutes);
        $this->assertSame(30.5, (new WorkTime($profile))->leave(2026, collect())['entitlement']);

        Volt::test('work.index')->assertDontSee('Kundentermin Partner');
    }
}
