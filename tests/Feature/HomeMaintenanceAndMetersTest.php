<?php

namespace Tests\Feature;

use App\Models\HomeTask;
use App\Models\Meter;
use App\Notifications\LifeReminder;
use App\Support\CalendarEvents;
use App\Support\Life\MeterStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HomeMaintenanceAndMetersTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_jobs_from_the_library_are_planned_done_and_reminded(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['reminder_emails' => true]);
        $this->actingAs($user);

        Volt::test('house.index')
            ->set('showLibrary', true)
            ->assertSee('Heizung warten')
            ->set('picked', ['heating_service', 'gutters', 'smoke_detectors'])
            ->call('addPicked')
            ->set('picked', ['gutters'])
            ->call('addPicked')
            ->assertSee('Dachrinne reinigen');

        $this->assertSame(3, HomeTask::count());
        $gutters = HomeTask::where('key', 'gutters')->sole();
        $this->assertSame('2026-11-01', $gutters->next_due_on->toDateString());
        $this->assertSame('2026-09-01', HomeTask::where('key', 'heating_service')->sole()->next_due_on->toDateString());

        Volt::test('house.index')->call('done', $gutters->id);
        $this->assertSame(['2026-09-28', '2027-09-28'], [$gutters->fresh()->last_done_on->toDateString(), $gutters->fresh()->next_due_on->toDateString()]);

        $smoke = HomeTask::where('key', 'smoke_detectors')->sole();
        $smoke->update(['next_due_on' => '2026-10-05']);
        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => str_contains($notification->events[0]['title'], 'Rauchmelder testen'));

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-12-31'), [CalendarEvents::LIFE]);
        $this->assertContains('🚨 Rauchmelder testen', array_column($events, 'title'));

        $this->actingAs($this->addMemberTo($household));
        Volt::test('house.index')->assertSee('Rauchmelder testen');
    }

    public function test_meter_readings_project_consumption_and_check_the_advance(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('house.index', ['tab' => 'meters'])
            ->call('newMeter')
            ->set('meter.name', 'Strom Haus')->set('meter.price_per_unit', '0,40')->set('meter.base_fee_monthly', '10')->set('meter.monthly_advance', '80')
            ->call('saveMeter')
            ->assertHasNoErrors();

        $meter = Meter::sole();
        $this->assertSame([0.4, 10.0, 80.0], [$meter->price_per_unit, $meter->base_fee_monthly, $meter->monthly_advance]);

        $page->set("reading.{$meter->id}.read_on", '2026-03-01')->set("reading.{$meter->id}.value", '10.000')->call('addReading', $meter->id)->assertHasNoErrors()
            ->set("reading.{$meter->id}.read_on", '2026-08-28')->set("reading.{$meter->id}.value", '11500')->call('addReading', $meter->id)
            ->set("reading.{$meter->id}.read_on", '2027-01-01')->set("reading.{$meter->id}.value", '12000')->call('addReading', $meter->id)->assertHasErrors("reading.{$meter->id}.read_on");

        $stats = MeterStats::for($meter->fresh());
        // 1,500 kWh in 180 days → 3,041.7 kWh a year; × 0.40 € + 120 € base fee.
        $this->assertSame(3041.7, $stats['yearly']);
        $this->assertSame(1336.68, $stats['yearly_cost']);
        $this->assertSame(-376.68, $stats['balance']);
        $this->assertSame(112.0, $stats['suggested_advance']);

        $page->assertSee('Erwartete Nachzahlung')->assertSee('376,68 €')->assertSee('112,00 €');

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'), [CalendarEvents::LIFE]);
        $this->assertSame(['2026-12-31'], array_column($events, 'date'));
    }
}
