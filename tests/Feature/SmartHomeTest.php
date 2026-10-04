<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Item;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SmartHomeTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const WIREGUARD = "[Interface]\nPrivateKey = cGFzc3dvcmRwYXNzd29yZHBhc3N3b3JkcGFzc3dvcmQ=\nAddress = 10.8.0.2/32\n\n[Peer]\nPublicKey = cHVibGljcHVibGljcHVibGljcHVibGljcHVibGljcHU=\nAllowedIPs = 192.168.1.0/24\nEndpoint = vpn.example.com:51820\n";

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['vpn.enabled' => true, 'vpn.wireguard_config' => self::WIREGUARD, 'vpn.socks_port' => 25555]);
    }

    private function states(): array
    {
        return [
            ['entity_id' => 'light.wohnzimmer', 'state' => 'off', 'attributes' => ['friendly_name' => 'Wohnzimmer']],
            ['entity_id' => 'sensor.aussen', 'state' => '12.5', 'attributes' => ['friendly_name' => 'Außentemperatur', 'unit_of_measurement' => '°C']],
        ];
    }

    public function test_the_owner_connects_home_assistant_picks_devices_and_switches_them(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        Http::fake([
            '192.168.1.10:8123/api/states' => Http::response($this->states()),
            '192.168.1.10:8123/api/states/light.wohnzimmer' => Http::response(['entity_id' => 'light.wohnzimmer', 'state' => 'on', 'attributes' => ['friendly_name' => 'Wohnzimmer']]),
            '192.168.1.10:8123/api/services/*' => Http::response([]),
        ]);

        $this->actingAs($member);
        Volt::test('smart-home.index')->call('create', 'homeassistant')->assertForbidden();

        $this->actingAs($owner);
        Volt::test('smart-home.index', ['tab' => 'settings'])->set('tab', 'settings')
            ->call('create', 'homeassistant')
            ->set('form.url', 'http://10.0.0.1:8123')->set('form.token', 'geheim')->call('save')->assertHasErrors('form.url')
            ->set('form.url', 'http://192.168.1.10:8123')->call('save')->assertHasNoErrors()->assertDispatched('toast');

        $connection = SmartHomeConnection::sole();
        $this->assertSame(['vpn', 'geheim'], [$connection->last_route, $connection->token]);
        $this->assertStringNotContainsString('geheim', (string) DB::table('smart_home_connections')->value('token'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer geheim'));

        Volt::test('smart-home.index', ['tab' => 'settings'])->set('tab', 'settings')
            ->call('loadEntities')->assertSee('Außentemperatur')
            ->call('addEntity', 'light.wohnzimmer')->call('addEntity', 'sensor.aussen');

        $light = SmartHomeEntity::where('entity_id', 'light.wohnzimmer')->sole();
        Volt::test('smart-home.index')->assertSee('12,5 °C')->call('pressEntity', $light->id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_on') && $request['entity_id'] === 'light.wohnzimmer');
        $this->assertSame('on', $light->fresh()->state);

        // Other households never see the devices.
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('smart-home.index')->call('pressEntity', $light->id)->assertNotFound();
    }

    public function test_the_external_address_is_used_when_the_vpn_is_down_and_evcc_switches_the_charge_mode(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $homeAssistant = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'url' => 'http://192.168.1.10:8123', 'external_url' => 'https://abc.ui.nabu.casa', 'token' => 't']);
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'evcc', 'name' => 'evcc', 'url' => 'http://192.168.1.20:7070']);
        Http::fake([
            '192.168.1.10:8123/*' => Http::failedConnection(),
            'abc.ui.nabu.casa/api/states' => Http::response($this->states()),
            '192.168.1.20:7070/api/state' => Http::response(['result' => ['pvPower' => 5230, 'homePower' => 640, 'grid' => ['power' => -4100], 'batterySoc' => 87, 'loadpoints' => [
                ['title' => 'Garage', 'mode' => 'pv', 'charging' => true, 'connected' => true, 'chargePower' => 3700, 'vehicleTitle' => 'ID.4', 'vehicleSoc' => 54, 'limitSoc' => 80],
            ]]]),
            '192.168.1.20:7070/api/loadpoints/*' => Http::response([]),
        ]);

        $this->assertCount(2, $homeAssistant->homeAssistant()->states());
        $this->assertSame('external', $homeAssistant->fresh()->last_route);

        $this->actingAs($owner);
        Volt::test('smart-home.index')->assertSee('5,2 kW')->assertSee('Einspeisung')->assertSee('ID.4')->assertSee('54 %')
            ->call('chargeMode', 1, 'now')->call('chargeLimit', 1, 90);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/loadpoints/1/mode/now') && $request->method() === 'POST');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/loadpoints/1/limitsoc/90'));
    }

    public function test_home_assistant_reports_changes_through_the_webhook_and_automations_react(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'url' => 'http://192.168.1.10:8123', 'token' => 't']);
        $window = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'binary_sensor.fenster', 'name' => 'Fenster', 'state' => 'off']);
        Automation::factory()->create([
            'household_id' => $household->id, 'user_id' => $owner->id, 'name' => 'Fenster offen',
            'trigger' => ['type' => 'entity', 'entity_id' => 'binary_sensor.fenster', 'operator' => 'eq', 'value' => 'on'],
            'actions' => [['type' => 'task', 'title' => 'Fenster schließen', 'due' => 'today']],
        ]);

        $this->postJson(route('api.v1.smart-home.webhook', str_repeat('x', 48)), ['entity_id' => 'binary_sensor.fenster', 'state' => 'on'])->assertNotFound();
        $this->postJson(route('api.v1.smart-home.webhook', $connection->webhook_secret), ['entity_id' => 'binary_sensor.fenster', 'state' => 'on'])->assertOk()->assertJson(['accepted' => 1]);
        // The same state again does not start the automation a second time.
        $this->postJson(route('api.v1.smart-home.webhook', $connection->webhook_secret), [['entity_id' => 'binary_sensor.fenster', 'state' => 'on'], ['entity_id' => 'bad id', 'state' => 'x']])->assertJson(['accepted' => 1]);

        $this->assertSame('on', $window->fresh()->state);
        $this->assertSame(1, Item::where('title', 'Fenster schließen')->count());
        $this->assertSame('webhook', $connection->fresh()->last_route);
    }
}
