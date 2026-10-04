<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Collection;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\PresenceSimulation;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\CalendarMatcher;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-05 08:00'));
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function fromTemplate(User $user, int $householdId, string $key, array $changes = []): Automation
    {
        $template = AutomationCatalog::templates()[$key];

        return Automation::create($changes + ['household_id' => $householdId, 'user_id' => $user->id, 'name' => $template['name'], 'trigger' => $template['trigger'], 'conditions' => $template['conditions'], 'actions' => $template['actions'], 'active' => true]);
    }

    public function test_holiday_in_the_calendar_switches_holiday_mode_which_simulates_presence_until_the_holiday_ends(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://abc.ui.nabu.casa', 'token' => 't']);
        foreach (['light.wohnzimmer', 'light.kueche', 'light.flur'] as $light) {
            SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => $light, 'name' => $light]);
        }
        Http::fake(['abc.ui.nabu.casa/*' => Http::response([])]);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->twice()->withArgs(fn (User $to, string $title) => $title === 'Urlaubsmodus ist an'));

        $this->fromTemplate($user, $household->id, 'vacation_on');
        $this->fromTemplate($user, $household->id, 'vacation_off');
        $this->fromTemplate($user, $household->id, 'presence', ['actions' => [
            ['type' => 'presence_start', 'entities' => ['light.wohnzimmer', 'light.kueche', 'light.flur'], 'from' => '18:00', 'until' => '23:30'],
            ['type' => 'push', 'to' => 'household', 'title' => 'Urlaubsmodus ist an', 'body' => '', 'urgent' => false],
        ]]);
        $this->fromTemplate($user, $household->id, 'presence_off');

        // Leave of the partner from tomorrow until Wednesday (entered under "Working time & leave").
        foreach (['2026-10-06', '2026-10-07'] as $day) {
            WorkDay::create(['user_id' => $partner->id, 'date' => $day, 'type' => 'vacation']);
        }

        $this->artisan('automations:run')->assertSuccessful();
        $this->assertFalse(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->active);

        $this->travelTo(Carbon::parse('2026-10-06 00:01'));
        $this->artisan('automations:run')->assertSuccessful();
        $this->assertTrue(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->active);
        $simulation = PresenceSimulation::sole();
        $this->assertTrue($simulation->active);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/services/'));

        // In the evening one light goes on; a while later the light changes rooms.
        $this->travelTo(Carbon::parse('2026-10-06 19:00'));
        $this->artisan('automations:run');
        $this->assertCount(1, $simulation->fresh()->lit);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_on'));

        $this->travelTo(Carbon::parse('2026-10-06 19:45'));
        $this->artisan('automations:run');
        $this->assertCount(1, $simulation->fresh()->lit);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_off'));

        // After the window all lights are off.
        $this->travelTo(Carbon::parse('2026-10-06 23:40'));
        $this->artisan('automations:run');
        $this->assertSame([], $simulation->fresh()->lit);

        // Back on Thursday: holiday mode and the simulation end.
        $this->travelTo(Carbon::parse('2026-10-08 06:00'));
        $this->artisan('automations:run');
        $this->assertFalse(HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->active);
        $this->assertFalse($simulation->fresh()->active);
        $this->assertSame(['done'], Automation::where('name', AutomationCatalog::templates()['presence_off']['name'])->sole()->runs()->pluck('status')->all());
    }

    public function test_a_calendar_entry_with_the_word_holiday_counts_and_time_triggers_respect_conditions(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $entry = Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => 'Urlaub Mallorca', 'created_by' => $user->id]);
        $entry->setPropertyValue($tasks->systemProperty('due'), '2026-10-05');
        $entry->save();
        $this->assertTrue(CalendarMatcher::active($user, $household->id, 'urlaub', ['calendar'], now()));
        $this->assertFalse(CalendarMatcher::active($user, $household->id, 'urlaub', ['calendar'], now()->addDay()));

        $morning = Automation::factory()->create([
            'household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Pflanzen gießen',
            'trigger' => ['type' => 'time', 'at' => '08:00', 'days' => []],
            'conditions' => [['type' => 'mode', 'key' => 'vacation', 'active' => false], ['type' => 'weekdays', 'days' => [1]]],
            'actions' => [['type' => 'task', 'title' => 'Pflanzen gießen', 'due' => 'today']],
        ]);

        $this->artisan('automations:run');
        $this->artisan('automations:run');
        $this->assertSame(1, Item::where('title', 'Pflanzen gießen')->count());

        // In holiday mode the condition fails and the run is noted as skipped.
        HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->update(['active' => true]);
        $this->travelTo(Carbon::parse('2026-10-12 08:00'));
        $this->artisan('automations:run');
        $this->assertSame(['skipped', 'done'], $morning->runs()->latest('id')->pluck('status')->all());
    }

    public function test_automations_are_built_in_the_editor_and_run_by_hand(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once());

        Volt::test('automations.index')
            ->call('create', 'vacation_on')->assertSet('form.trigger.type', 'calendar')
            ->set('form.name', 'Urlaub & Gäste')
            ->call('addAction', 'push')->set('form.actions.1.title', 'Gute Reise')
            ->call('addAction', 'ha_service')->set('form.actions.2.data', '{kaputt')->call('save')->assertHasErrors('form.actions.2.data')
            ->call('removeAction', 2)->call('addCondition', 'time_window')->set('form.conditions.0.from', '25:00')->call('save')->assertHasErrors('form.conditions.0.from')
            ->call('removeCondition', 0)->call('save')->assertHasNoErrors()
            ->assertSee('Urlaub & Gäste');

        $automation = Automation::sole();
        $this->assertSame([['type' => 'mode', 'key' => 'vacation', 'active' => true], ['type' => 'push', 'to' => 'me', 'title' => 'Gute Reise', 'body' => '', 'urgent' => false]], $automation->actions);

        Volt::test('automations.index')->call('runNow', $automation->id)->assertSet('logFor', $automation->id)->assertSee('Gestartet von Hand');
        $this->assertTrue(HouseholdMode::where('household_id', $household->id)->where('key', 'vacation')->sole()->active);

        Volt::test('automations.index')->call('toggleMode', 'vacation');
        $this->assertFalse(HouseholdMode::where('household_id', $household->id)->where('key', 'vacation')->sole()->active);

        // Someone else's automation cannot be touched.
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('automations.index')->call('runNow', $automation->id)->assertNotFound();
    }

    public function test_evcc_values_fire_once_when_crossing_the_threshold(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'evcc', 'name' => 'evcc', 'external_url' => 'https://evcc.example.com']);
        $pv = 5000;
        Http::fake(['evcc.example.com/api/state' => function () use (&$pv) {
            return Http::response(['pvPower' => $pv, 'loadpoints' => [['connected' => true]]]);
        }]);
        $automation = $this->fromTemplate($user, $household->id, 'pv_surplus', ['conditions' => [], 'actions' => [['type' => 'task', 'title' => 'Waschmaschine an', 'due' => 'today']]]);

        $this->artisan('automations:run');
        $this->travelTo(now()->addMinutes(5));
        $this->artisan('automations:run');
        $pv = 1000;
        $this->travelTo(now()->addMinutes(5));
        $this->artisan('automations:run');
        $pv = 4500;
        $this->travelTo(now()->addMinutes(5));
        $this->artisan('automations:run');

        $this->assertSame(2, Item::where('title', 'Waschmaschine an')->count());
        $this->assertTrue($automation->fresh()->trigger_state);
    }
}
