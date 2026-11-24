<?php

namespace Tests\Feature;

use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Smart home setup for the app: connections, picking devices, sliders and the evcc charge mode.
 */
class ApiSmartHomeSetupTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const WIREGUARD = "[Interface]\nPrivateKey = cGFzc3dvcmRwYXNzd29yZHBhc3N3b3JkcGFzc3dvcmQ=\nAddress = 10.8.0.2/32\n\n[Peer]\nPublicKey = cHVibGljcHVibGljcHVibGljcHVibGljcHVibGljcHU=\nAllowedIPs = 192.168.1.0/24\nEndpoint = vpn.example.com:51820\n";

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['vpn.enabled' => true, 'vpn.wireguard_config' => self::WIREGUARD, 'vpn.socks_port' => 25555]);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_owner_connects_picks_devices_and_the_member_cannot(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $household->setAddon('smart_home', true);
        $member = $this->addMemberTo($household);
        Http::fake([
            '192.168.1.10:8123/api/states' => Http::response([
                ['entity_id' => 'light.wohnzimmer', 'state' => 'off', 'attributes' => ['friendly_name' => 'Wohnzimmer']],
                ['entity_id' => 'sensor.aussen', 'state' => '12.5', 'attributes' => ['friendly_name' => 'Außentemperatur', 'unit_of_measurement' => '°C']],
            ]),
        ]);
        $headers = $this->headersFor($owner);

        $this->getJson('/api/v1/smart-home/setup', $headers)->assertOk()->assertJsonPath('data.owner', true)->assertJsonPath('data.connections', []);
        $this->postJson('/api/v1/smart-home/connections', ['type' => 'homeassistant', 'name' => 'HA', 'url' => 'http://192.168.1.10:8123', 'token' => 'geheim'], $headers)
            ->assertCreated()->assertJsonPath('data.ok', true);
        $this->postJson('/api/v1/smart-home/connections', ['type' => 'homeassistant', 'name' => 'Falsch', 'url' => 'http://10.99.99.1:8123', 'token' => 'x'], $headers)->assertStatus(422);
        $connection = SmartHomeConnection::sole();
        $this->assertSame('geheim', $connection->token);

        $this->getJson('/api/v1/smart-home/available', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/smart-home/entities', ['entity_id' => 'light.wohnzimmer'], $headers)->assertCreated();
        $this->getJson('/api/v1/smart-home/available?q=auss', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entity_id', 'sensor.aussen');
        $entity = SmartHomeEntity::sole();
        $this->putJson("/api/v1/smart-home/entities/{$entity->id}", ['area' => 'Wohnzimmer', 'favorite' => true, 'name' => 'Stehlampe'], $headers)->assertOk();
        $this->assertSame(['Stehlampe', 'Wohnzimmer', true], [$entity->refresh()->name, $entity->area, (bool) $entity->favorite]);
        $this->putJson("/api/v1/smart-home/connections/{$connection->id}", ['name' => 'Zuhause', 'url' => 'http://192.168.1.10:8123'], $headers)->assertOk();
        $this->assertSame('geheim', $connection->refresh()->token);

        $memberHeaders = $this->headersFor($member);
        $this->postJson('/api/v1/smart-home/connections', ['type' => 'homeassistant', 'name' => 'X', 'url' => 'http://192.168.1.10:8123', 'token' => 'x'], $memberHeaders)->assertForbidden();
        $this->deleteJson("/api/v1/smart-home/entities/{$entity->id}", [], $memberHeaders)->assertForbidden();

        $this->deleteJson("/api/v1/smart-home/entities/{$entity->id}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/smart-home/connections/{$connection->id}", [], $headers)->assertOk();
        $this->assertSame(0, SmartHomeConnection::count());
    }

    public function test_the_charge_mode_and_limit(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $household->setAddon('smart_home', true);
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'evcc', 'name' => 'evcc', 'url' => 'http://192.168.1.20:7070']);
        Http::fake([
            '192.168.1.20:7070/api/state' => Http::response(['result' => ['pvPower' => 5230, 'loadpoints' => [['title' => 'Garage', 'mode' => 'pv', 'charging' => true, 'connected' => true, 'chargePower' => 3700, 'limitSoc' => 80]]]]),
            '192.168.1.20:7070/api/loadpoints/*' => Http::response([]),
        ]);
        $headers = $this->headersFor($owner);

        $this->getJson('/api/v1/smart-home/setup', $headers)->assertOk()->assertJsonPath('data.evcc.loadpoints.0.title', 'Garage');
        $this->postJson('/api/v1/smart-home/charging/1/mode', ['mode' => 'now'], $headers)->assertOk();
        $this->postJson('/api/v1/smart-home/charging/1/mode', ['mode' => 'warp'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/smart-home/charging/1/limit', ['soc' => 90], $headers)->assertOk();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/loadpoints/1/limitsoc/90'));
    }

    public function test_dashboards_can_be_built_and_arranged(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $household->setAddon('smart_home', true);
        $member = $this->addMemberTo($household);
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'url' => 'http://192.168.1.10:8123', 'token' => 't']);
        $lamp = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'light.sofa', 'name' => 'Sofa', 'area' => 'Wohnzimmer', 'state' => 'on']);
        SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'light.tv', 'name' => 'TV', 'area' => 'Wohnzimmer', 'state' => 'off']);
        $headers = $this->headersFor($owner);

        $id = $this->postJson('/api/v1/smart-home/dashboards', ['name' => 'Wohnzimmer'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['entity_id' => $lamp->id], $headers)->assertCreated();
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['title' => 'Abends'], $headers)->assertCreated();
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['area' => 'Wohnzimmer'], $headers)->assertCreated();
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['area' => 'Keller'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['kind' => 'energy'], $headers)->assertCreated();

        $show = $this->getJson("/api/v1/smart-home/dashboards/{$id}", $headers)->assertOk()->assertJsonPath('data.editable', true)->assertJsonPath('data.cards.0.device.name', 'Sofa');
        $this->assertCount(6, $show->json('data.cards'));
        $card = $show->json('data.cards.1.id');

        $this->putJson("/api/v1/smart-home/cards/{$card}", ['title' => 'Am Abend', 'size' => 'full'], $headers)->assertOk();
        $this->putJson("/api/v1/smart-home/cards/{$card}", ['size' => 'huge'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/smart-home/cards/{$card}/move", ['position' => 0], $headers)->assertOk();
        $this->getJson("/api/v1/smart-home/dashboards/{$id}", $headers)->assertJsonPath('data.cards.0.title', 'Am Abend');
        $this->deleteJson("/api/v1/smart-home/cards/{$card}", [], $headers)->assertOk();

        // A member sees the shared dashboard but cannot change it.
        $memberHeaders = $this->headersFor($member);
        $this->getJson("/api/v1/smart-home/dashboards/{$id}", $memberHeaders)->assertOk()->assertJsonPath('data.editable', false);
        $this->postJson("/api/v1/smart-home/dashboards/{$id}/cards", ['title' => 'X'], $memberHeaders)->assertForbidden();
        $this->deleteJson("/api/v1/smart-home/dashboards/{$id}", [], $memberHeaders)->assertForbidden();

        $this->putJson("/api/v1/smart-home/dashboards/{$id}", ['name' => 'Wohnen', 'personal' => true], $headers)->assertOk();
        $this->getJson("/api/v1/smart-home/dashboards/{$id}", $memberHeaders)->assertNotFound();
        $this->deleteJson("/api/v1/smart-home/dashboards/{$id}", [], $headers)->assertOk();
    }
}
