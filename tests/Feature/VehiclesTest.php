<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Notifications\LifeReminder;
use App\Support\CalendarEvents;
use App\Support\Finance\CashflowStatement;
use App\Support\Life\VehicleStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class VehiclesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_a_vehicle_is_created_and_its_log_adds_up_to_costs_and_consumption(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('vehicles.index')
            ->set('vehicle.name', 'Familienauto')->set('vehicle.plate', 'm-ab 123')->set('vehicle.odometer_start', '40000')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $vehicle = Vehicle::sole();
        $this->assertSame([$household->id, 'M-AB 123', Item::VISIBILITY_SHARED], [$vehicle->household_id, $vehicle->plate, $vehicle->visibility]);

        $page = Volt::test('vehicles.show', ['vehicle' => $vehicle])
            ->set('tab', 'details')
            ->set('details.inspection_due', '2026-10-28')->set('details.tax_yearly', '120')->set('details.insurance_yearly', '480')->set('details.current_value', '12.000,00')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->set('tab', 'log');

        foreach ([['2026-07-01', 40000, 50], ['2026-08-01', 40600, 36], ['2026-09-01', 41000, 24]] as [$date, $odometer, $litres]) {
            $page->set('entry.kind', 'fuel')->set('entry.date', $date)->set('entry.odometer', (string) $odometer)->set('entry.quantity', (string) $litres)->set('entry.amount', '60,00')->call('addEntry')->assertHasNoErrors();
        }

        $page->set('entry.kind', 'service')->set('entry.amount', '240')->set('entry.notes', 'Ölwechsel')->call('addEntry');

        $stats = VehicleStats::for($vehicle->fresh());
        $this->assertSame(41000, $stats['odometer']);
        $this->assertSame(1000, $stats['km_12m']);
        $this->assertSame(1020.0, $stats['costs_12m']);
        $this->assertSame(85.0, $stats['monthly']);
        $this->assertSame(1.02, $stats['per_km']);
        $this->assertSame(6.0, $stats['consumption']);

        $page->assertSee('85,00 €')->assertSee('6,0 l/100 km')->assertSee('Ölwechsel');

        $page->set('tab', 'trips')
            ->set('entry.distance_km', '120')->set('entry.purpose', 'business')->set('entry.destination', 'Kunde Augsburg')
            ->call('addEntry')
            ->assertHasNoErrors()
            ->assertSee('Kunde Augsburg')
            ->assertSee('36,00 €');

        $this->assertSame(['trip', 'business', 120], [VehicleLog::latest('id')->first()->kind, VehicleLog::latest('id')->first()->purpose, VehicleLog::latest('id')->first()->distance_km]);

        $cashflow = CashflowStatement::for($user);
        $this->assertContains('Familienauto (Fahrzeug)', array_column($cashflow['doodads']['rows'], 'label'));
    }

    public function test_inspection_and_tyre_changes_are_reminded_to_the_owner_and_shown_to_the_household(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $user->update(['reminder_emails' => true]);
        $partner->update(['reminder_emails' => true]);
        Vehicle::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Familienauto', 'inspection_due' => '2026-10-28']);

        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => str_contains($notification->events[0]['title'], 'Familienauto'));
        Notification::assertNotSentTo($partner, LifeReminder::class);

        $events = CalendarEvents::between($partner, $household->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [CalendarEvents::LIFE]);
        $this->assertSame(['2026-10-15', '2026-10-28'], array_column($events, 'date'));
        $this->assertStringContainsString('Winterreifen', $events[0]['title']);
    }

    public function test_private_vehicles_stay_hidden_from_the_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $vehicle = Vehicle::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Motorrad', 'visibility' => Item::VISIBILITY_PRIVATE]);

        $this->actingAs($partner);
        Volt::test('vehicles.index')->assertDontSee('Motorrad');
        $this->get(route('vehicles.show', $vehicle))->assertNotFound();
    }
}
