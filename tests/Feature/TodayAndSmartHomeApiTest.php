<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Support\Addons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TodayAndSmartHomeApiTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function headersFor(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_app_gets_my_day_widgets_and_files_text_with_one_input(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:00'));
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $task = Item::create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'type' => Item::TYPE_TODO, 'title' => 'Zahnarzt', 'due_time' => '09:30']);
        $task->setPropertyValue($tasks->systemProperty('due'), '2026-10-05');
        $task->save();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/today', $headers)->assertOk()
            ->assertJsonPath('data.mode', 'morning')->assertJsonPath('data.next.title', 'Zahnarzt')->assertJsonPath('data.minutes_until_next', 90);

        $this->postJson('/api/v1/capture', ['text' => 'Einkauf: Milch, Butter'], $headers)->assertCreated()->assertJsonPath('data.kind', 'shopping');
        $this->assertSame(2, ShoppingListEntry::count());
        $this->postJson('/api/v1/capture', ['text' => ''], $headers)->assertStatus(422);

        $this->getJson('/api/v1/widgets', $headers)->assertOk()
            ->assertJsonPath('data.next.title', 'Zahnarzt')->assertJsonPath('data.shopping_open', 2)->assertJsonPath('data.events_today', 1);
    }

    public function test_the_app_switches_devices_and_modes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['ha.example.com/api/services/*' => Http::response([]), 'ha.example.com/api/states/*' => Http::response(['entity_id' => 'light.flur', 'state' => 'on', 'attributes' => []])]);
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::SMART_HOME => true]])->save();
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        $light = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'light.flur', 'name' => 'Flur', 'state' => 'off', 'favorite' => true]);
        $lock = SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'lock.haustuer', 'name' => 'Haustür', 'state' => 'locked']);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/smart-home', $headers)->assertOk()
            ->assertJsonPath('data.may_control', true)->assertJsonPath('data.devices.0.name', 'Flur')->assertJsonPath('data.devices.1.critical', true);

        $this->postJson("/api/v1/smart-home/devices/{$light->id}/press", [], $headers)->assertOk();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_on'));

        // A lock is only switched with confirmation.
        $this->postJson("/api/v1/smart-home/devices/{$lock->id}/press", [], $headers)->assertStatus(502);

        $this->postJson('/api/v1/smart-home/modes/vacation', ['active' => true], $headers)->assertOk()->assertJsonPath('data.active', true);
        $this->assertTrue(HouseholdMode::where('household_id', $household->id)->where('key', 'vacation')->value('active'));

        [$stranger] = $this->memberWithHousehold();
        $this->postJson("/api/v1/smart-home/devices/{$light->id}/press", [], $this->headersFor($stranger))->assertNotFound();
    }
}
