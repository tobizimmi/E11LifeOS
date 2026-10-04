<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeDashboard;
use App\Models\SmartHomeDashboardCard;
use App\Models\SmartHomeEntity;
use App\Models\SmartHomeReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SmartHomeDashboardTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{0: SmartHomeEntity, 1: SmartHomeEntity, 2: SmartHomeEntity}
     */
    private function entities(Household $household): array
    {
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        $make = fn (string $id, string $name, string $state, ?string $area, ?string $unit = null) => SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => $id, 'name' => $name, 'state' => $state, 'area' => $area, 'unit' => $unit]);

        return [
            $make('light.sofa', 'Sofalicht', 'off', 'Wohnzimmer'),
            $make('sensor.wohnzimmer_temp', 'Raumtemperatur', '21.5', 'Wohnzimmer', '°C'),
            $make('sensor.pv', 'PV-Leistung', '4200', 'Technik', 'W'),
        ];
    }

    public function test_a_member_builds_a_dashboard_with_displays_sizes_and_order(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        [$light, $temperature, $pv] = $this->entities($household);
        $this->actingAs($owner);

        Volt::test('smart-home.index')->call('addDashboard')->assertSet('tab', 'board')->assertSet('newBoard', true);
        $dashboard = SmartHomeDashboard::sole();
        $this->assertSame($owner->id, $dashboard->created_by);

        $board = Volt::test('smart-home.dashboard', ['dashboardId' => $dashboard->id, 'editing' => true])
            ->set('settings.name', 'Wohnzimmer')->set('settings.icon', '🛋️')->call('saveSettings')->assertHasNoErrors()
            ->set('newArea', 'Wohnzimmer')->call('addArea')
            ->set('newEntity', (string) $pv->id)->call('addEntity')
            ->set('newHeading', 'Technik')->call('addHeading');

        $cards = $dashboard->cards()->get();
        $this->assertSame(['heading', 'entity', 'entity', 'entity', 'heading'], $cards->pluck('kind')->all());
        $this->assertSame('Wohnzimmer', $cards[0]->title);
        // Numeric sensors start as a large value, switches as a tile.
        $this->assertSame(['value', 'tile', 'value'], $cards->where('kind', 'entity')->pluck('display')->values()->all());

        $pvCard = $cards->firstWhere('smart_home_entity_id', $pv->id);
        $board->call('updateCard', $pvCard->id, 'display', 'gauge')
            ->call('updateCard', $pvCard->id, 'size', 'large')
            ->call('updateCard', $pvCard->id, 'max', '10000')
            ->call('updateCard', $pvCard->id, 'title', 'Solar')
            ->call('moveCard', $pvCard->id, 0);

        $pvCard->refresh();
        $this->assertEquals(['gauge', 'large', 'Solar', 10000, 0], [$pvCard->display, $pvCard->size, $pvCard->title, $pvCard->options['max'], $pvCard->position]);
        $this->assertSame('col-span-2 row-span-2 sm:min-h-64', $pvCard->sizeClasses());

        // A switch has no gauge, a sensor no button.
        $lightCard = $cards->firstWhere('smart_home_entity_id', $light->id);
        $board->call('updateCard', $lightCard->id, 'display', 'button')->assertOk();
        Volt::test('smart-home.dashboard', ['dashboardId' => $dashboard->id])->call('updateCard', $lightCard->id, 'display', 'gauge')->assertStatus(422);

        Volt::test('smart-home.dashboard', ['dashboardId' => $dashboard->id])
            ->assertSee('🛋️ Wohnzimmer')->assertSee('Solar')->assertSee('21,5 °C')->assertSee('aria-pressed="false"', false);

        Volt::test('smart-home.index')->assertSee('🛋️ Wohnzimmer')->call('openBoard', $dashboard->id)->assertSet('board', $dashboard->id)->assertSee('Solar');

        $board->call('removeCard', $lightCard->id)->call('deleteDashboard')->assertDispatched('smart-home-dashboard-deleted');
        $this->assertSame(0, SmartHomeDashboardCard::count());
    }

    public function test_personal_dashboards_stay_private_and_shared_ones_are_edited_by_creator_and_owner(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        $this->entities($household);
        $personal = SmartHomeDashboard::factory()->create(['household_id' => $household->id, 'created_by' => $member->id, 'personal' => true, 'name' => 'Mein Board']);
        $shared = SmartHomeDashboard::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id, 'name' => 'Erdgeschoss']);

        $this->actingAs($owner);
        Volt::test('smart-home.index')->assertSee('Erdgeschoss')->assertDontSee('Mein Board')->call('openBoard', $personal->id)->assertNotFound();
        Volt::test('smart-home.dashboard', ['dashboardId' => $personal->id])->assertNotFound();

        $this->actingAs($member);
        Volt::test('smart-home.index')->assertSee('Mein Board')->assertSee('Erdgeschoss');
        Volt::test('smart-home.dashboard', ['dashboardId' => $shared->id])->assertDontSee('✏️')->call('toggleEditing')->assertForbidden();
        Volt::test('smart-home.dashboard', ['dashboardId' => $shared->id])->call('deleteDashboard')->assertForbidden();
        Volt::test('smart-home.dashboard', ['dashboardId' => $personal->id])->call('toggleEditing')->assertSet('editing', true);

        // Another household never reaches the dashboard.
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('smart-home.dashboard', ['dashboardId' => $shared->id])->assertNotFound();
    }

    public function test_history_cards_show_recorded_values_and_tiles_switch_devices(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        [$light, $temperature] = $this->entities($household);
        $dashboard = SmartHomeDashboard::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        $dashboard->cards()->create(['smart_home_entity_id' => $temperature->id, 'display' => 'history', 'size' => 'full']);
        $dashboard->cards()->create(['smart_home_entity_id' => $light->id, 'display' => 'row']);

        foreach ([[3, 19.5], [2, 20.25], [1, 21.5]] as [$hoursAgo, $value]) {
            SmartHomeReading::create(['household_id' => $household->id, 'metric' => 'ha:sensor.wohnzimmer_temp', 'value' => $value, 'recorded_at' => now()->subHours($hoursAgo)]);
        }
        Http::fake(['ha.example.com/api/services/*' => Http::response([])]);

        $this->actingAs($owner);
        Volt::test('smart-home.dashboard', ['dashboardId' => $dashboard->id])
            ->assertSee('20,25 °C')->assertSee('role="switch"', false)
            ->call('pressEntity', $light->id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_on') && $request['entity_id'] === 'light.sofa');
    }
}
