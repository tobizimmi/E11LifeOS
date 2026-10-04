<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationPendingAction;
use App\Models\Collection;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\SecurityEvent;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeDay;
use App\Models\SmartHomeEntity;
use App\Models\SmartHomeReading;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\EnergyRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SmartHomeEnergyAndControlTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-05 10:00'));
    }

    public function test_energy_is_recorded_summed_up_per_day_and_handed_to_meters_and_the_car(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $evcc = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'evcc', 'name' => 'evcc', 'external_url' => 'https://evcc.example.com']);
        $homeAssistant = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        $car = Vehicle::factory()->create(['household_id' => $household->id, 'name' => 'ID.4', 'fuel' => 'electric']);
        $evcc->update(['vehicle_map' => [1 => $car->id]]);
        $meter = Meter::factory()->create(['household_id' => $household->id, 'name' => 'Strom', 'kind' => 'electricity', 'price_per_unit' => 0.30]);
        SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $homeAssistant->id, 'entity_id' => 'sensor.zaehler', 'name' => 'Zähler', 'state' => '12345.6', 'meter_id' => $meter->id]);
        Http::fake(['evcc.example.com/api/state' => Http::response(['pvPower' => 6000, 'homePower' => 1000, 'grid' => ['power' => -2000], 'loadpoints' => [['chargePower' => 3000, 'vehicleSoc' => 55]]])]);

        // One hour of values, every five minutes.
        $recorder = app(EnergyRecorder::class);
        for ($minute = 0; $minute < 60; $minute += 5) {
            $recorder->record(now()->setTime(10, $minute));
        }

        $energy = EnergyRecorder::energy($household->id, today());
        $this->assertEqualsWithDelta(6.0, $energy['pv_kwh'], 0.01);
        $this->assertEqualsWithDelta(2.0, $energy['export_kwh'], 0.01);
        $this->assertSame(0.0, $energy['import_kwh']);
        $this->assertEqualsWithDelta(3.0, $energy['charged_kwh'], 0.01);
        $this->assertEquals(['pv' => 6000.0, 'home' => 1000.0, 'grid' => -2000.0, 'charge' => 3000.0, 'vehicleSoc' => 55.0], array_intersect_key(EnergyRecorder::latest($household->id), array_flip(['pv', 'home', 'grid', 'charge', 'vehicleSoc'])));

        $this->actingAs($owner);
        Volt::test('smart-home.index', ['tab' => 'energy'])->set('tab', 'energy')->assertSee('6,0 kWh')->assertSee('Einspeisung');
        Volt::test('dashboard')->call('loadWidgets')->assertSee('Energie heute')->assertSee('55 %');

        $this->travelTo(Carbon::parse('2026-10-06 00:10'));
        $this->artisan('smart-home:energy --close')->assertSuccessful();

        $day = SmartHomeDay::sole();
        $this->assertSame([6.0, 100], [$day->pv_kwh, $day->autarky()]);
        $log = VehicleLog::where('vehicle_id', $car->id)->sole();
        $this->assertSame([3.0, 0.9, 'fuel'], [$log->quantity, $log->amount, $log->kind]);
        $this->assertSame(12345.6, (float) MeterReading::where('meter_id', $meter->id)->sole()->value);

        // Closing again changes nothing twice.
        $this->artisan('smart-home:energy --close');
        $this->assertSame(1, VehicleLog::count());
        $this->assertSame(1, MeterReading::count());
        $this->assertGreaterThan(0, SmartHomeReading::count());
    }

    public function test_critical_devices_need_a_confirmation_and_members_can_be_limited_to_viewing(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        $lock = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'lock.haustuer', 'name' => 'Haustür', 'state' => 'locked', 'favorite' => true]);
        $light = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'light.flur', 'name' => 'Flur', 'state' => 'off']);
        Http::fake([
            'ha.example.com/api/services/*' => Http::response([]),
            'ha.example.com/api/states/lock.haustuer' => Http::response(['entity_id' => 'lock.haustuer', 'state' => 'unlocked', 'attributes' => []]),
            'ha.example.com/api/states/light.flur' => Http::response(['entity_id' => 'light.flur', 'state' => 'on', 'attributes' => ['brightness' => 102]]),
        ]);

        $this->actingAs($owner);
        Volt::test('smart-home.index')->call('pressEntity', $lock->id)->assertDispatched('toast');
        Http::assertNothingSent();

        Volt::test('dashboard')->call('pressFavorite', $lock->id, true);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/lock/unlock'));
        $this->assertSame('unlocked', $lock->fresh()->state);
        $this->assertTrue(SecurityEvent::where('event', 'smart_home_critical')->where('user_id', $owner->id)->exists());

        Volt::test('smart-home.index')->call('setEntityValue', $light->id, 40);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/light/turn_on') && $request['brightness_pct'] === 40);

        // The owner takes away the right to switch: the member only sees the devices.
        Volt::test('household.show')->call('toggleMemberControl', $household->members()->where('user_id', $member->id)->value('id'));
        $this->actingAs($member);
        Volt::test('smart-home.index')->assertSee('Flur')->call('pressEntity', $light->id)->assertForbidden();
    }

    public function test_sun_appointments_due_tasks_presence_waiting_and_charging_plans(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $user->forceFill(['weather_latitude' => 48.14, 'weather_longitude' => 11.58])->save();
        $evcc = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'evcc', 'name' => 'evcc', 'external_url' => 'https://evcc.example.com']);
        $homeAssistant = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        $anna = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $homeAssistant->id, 'entity_id' => 'person.anna', 'name' => 'Anna', 'state' => 'home']);
        Http::fake(['evcc.example.com/api/vehicles/*' => Http::response([]), 'ha.example.com/*' => Http::response([])]);
        $make = fn (string $name, array $trigger, array $actions, array $conditions = []) => Automation::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => $name, 'trigger' => $trigger, 'conditions' => $conditions, 'actions' => $actions]);

        $sunset = AutomationEngine::sunTime($user, now(), 'sunset');
        $make('Abends', ['type' => 'sun', 'event' => 'sunset', 'offset' => -30], [['type' => 'task', 'title' => 'Rollläden', 'due' => 'today']]);
        $make('Termin', ['type' => 'appointment', 'keyword' => 'zahnarzt', 'minutes' => 30], [['type' => 'task', 'title' => 'Losfahren', 'due' => 'today']]);
        $make('Fällig', ['type' => 'task_due', 'keyword' => 'müll'], [['type' => 'task', 'title' => 'Tonne rausstellen', 'due' => 'today']]);
        $make('Alle weg', ['type' => 'presence', 'state' => 'all_away'], [['type' => 'mode', 'key' => 'away', 'active' => true], ['type' => 'delay', 'minutes' => 10], ['type' => 'evcc_plan', 'vehicle' => 'id4', 'soc' => 80, 'time' => '07:00']]);

        $tasks = Collection::tasksFor($household->id);
        foreach (['Zahnarzt' => '14:00', 'Müll' => '18:00'] as $title => $time) {
            $task = Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => $title, 'created_by' => $user->id, 'due_time' => $time]);
            $task->setPropertyValue($tasks->systemProperty('due'), today()->toDateString());
            $task->save();
        }

        $this->travelTo(today()->setTime(13, 30));
        $this->artisan('automations:run');
        $this->travelTo(today()->setTime(18, 0));
        $this->artisan('automations:run');
        $this->travelTo($sunset->copy()->subMinutes(30));
        $this->artisan('automations:run');
        $this->assertSame(['Losfahren', 'Rollläden', 'Tonne rausstellen'], Item::whereIn('title', ['Losfahren', 'Tonne rausstellen', 'Rollläden'])->orderBy('title')->pluck('title')->all());

        // Anna leaves: nobody at home, ten minutes later the charging plan is set.
        $anna->update(['state' => 'not_home']);
        $this->travelTo(today()->setTime(20, 0));
        $this->artisan('automations:run');
        $this->assertTrue(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'away')->active);
        $this->assertSame(1, AutomationPendingAction::count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/vehicles/'));

        $this->travelTo(today()->setTime(20, 10));
        $this->artisan('automations:run');
        $this->assertSame(0, AutomationPendingAction::count());
        Http::assertSent(fn (Request $request) => str_contains(rawurldecode($request->url()), '/api/vehicles/id4/plan/soc/80/2026-10-06T07:00:00'));
    }

    public function test_the_command_bar_runs_automations_switches_modes_and_devices(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $automation = Automation::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Gute Nacht', 'trigger' => ['type' => 'manual'], 'actions' => [['type' => 'mode', 'key' => 'night', 'active' => true]]]);
        $this->actingAs($user);

        Volt::test('search-palette')->set('query', 'nacht')->assertSee('Automation „Gute Nacht“ ausführen')->call('runAutomation', $automation->id)->assertDispatched('toast');
        $this->assertTrue(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'night')->active);

        Volt::test('search-palette')->set('query', 'urlaub')->assertSee('„Urlaubsmodus“ einschalten')->call('toggleMode', 'vacation');
        $this->assertTrue(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->active);
    }
}
