<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\EntityStates;
use App\Support\SmartHome\HomeAssistantStream;
use App\Support\SmartHome\SmartHomeException;
use App\Support\SmartHome\WebSocketClient;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HomeAssistantLiveTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_stream_signs_in_subscribes_takes_areas_and_passes_state_changes_on(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'url' => 'http://192.168.1.10:8123', 'token' => 'geheim']);
        $door = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'binary_sensor.tuer', 'name' => 'Tür', 'state' => 'off']);
        Automation::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'trigger' => ['type' => 'entity', 'entity_id' => 'binary_sensor.tuer', 'operator' => 'eq', 'value' => 'on'], 'actions' => [['type' => 'push', 'to' => 'me', 'title' => 'Tür offen', 'body' => '', 'urgent' => true]]]);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->withArgs(fn ($to, string $title) => $title === 'Tür offen'));

        $sent = [];
        $stream = new HomeAssistantStream($connection, function (array $message) use (&$sent) {
            $sent[] = $message;
        }, app(AutomationEngine::class));

        $stream->handle(['type' => 'auth_required']);
        $this->assertSame(['type' => 'auth', 'access_token' => 'geheim'], $sent[0]);

        $stream->handle(['type' => 'auth_ok']);
        $this->assertTrue($stream->authenticated);
        $this->assertSame(['subscribe_events', 'config/area_registry/list', 'config/device_registry/list', 'config/entity_registry/list'], array_column(array_slice($sent, 1), 'type'));

        // Areas: the entity has none in E11, its device sits in the "Flur" area of Home Assistant.
        $stream->handle(['id' => 2, 'type' => 'result', 'result' => [['area_id' => 'flur', 'name' => 'Flur']]]);
        $stream->handle(['id' => 3, 'type' => 'result', 'result' => [['id' => 'dev1', 'area_id' => 'flur']]]);
        $stream->handle(['id' => 4, 'type' => 'result', 'result' => [['entity_id' => 'binary_sensor.tuer', 'device_id' => 'dev1', 'area_id' => null]]]);
        $this->assertSame('Flur', $door->fresh()->area);

        // Unknown entities are ignored, the door opens the automation at once.
        $stream->handle(['id' => 1, 'type' => 'event', 'event' => ['data' => ['entity_id' => 'sensor.unbekannt', 'new_state' => ['state' => '5']]]]);
        $this->assertNull(EntityStates::get($household->id, 'sensor.unbekannt'));
        $stream->handle(['id' => 1, 'type' => 'event', 'event' => ['data' => ['entity_id' => 'binary_sensor.tuer', 'new_state' => ['state' => 'on', 'attributes' => ['friendly_name' => 'Tür']]]]]);
        $this->assertSame('on', $door->fresh()->state);

        $this->expectException(SmartHomeException::class);
        $stream->handle(['type' => 'auth_invalid']);
    }

    public function test_the_client_reads_server_frames_answers_pings_and_masks_its_own(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($client, 1);
        $socket = WebSocketClient::onStream($client);

        $long = json_encode(['type' => 'event', 'text' => str_repeat('x', 300)]);
        fwrite($server, "\x89\x04ping"."\x81".chr(126).pack('n', strlen($long)).$long."\x81\x13".'{"type":"auth_ok"}'.' ');

        $this->assertSame(300, strlen($socket->receiveJson()['text']));
        $this->assertSame('auth_ok', $socket->receiveJson()['type']);

        // The ping was answered with a masked pong.
        $pong = fread($server, 2 + 4 + 4);
        $this->assertSame("\x8A", $pong[0]);
        $this->assertSame(0x80 | 4, ord($pong[1]));
        $this->assertSame('ping', substr($pong, 6, 4) ^ substr($pong, 2, 4));

        $socket->sendJson(['id' => 1, 'type' => 'ping']);
        $frame = fread($server, 2);
        $length = ord($frame[1]) & 0x7F;
        $mask = fread($server, 4);
        $payload = fread($server, $length) ^ str_repeat($mask, intdiv($length, 4) + 1);
        $this->assertSame(['id' => 1, 'type' => 'ping'], json_decode($payload, true));
    }

    public function test_polling_pauses_while_the_live_connection_reports(): void
    {
        [, $household] = $this->memberWithHousehold();
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't', 'last_route' => 'websocket', 'last_contact_at' => now()]);
        SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => SmartHomeConnection::sole()->id, 'entity_id' => 'light.a', 'name' => 'A']);
        Http::preventStrayRequests();

        $this->artisan('automations:run')->assertSuccessful();
    }
}
