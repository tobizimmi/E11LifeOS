<?php

namespace Tests\Feature;

use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\Transaction;
use App\Support\Addons;
use App\Support\SmartCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SmartCaptureTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_one_field_files_shopping_expenses_tasks_modes_devices_and_the_rest_in_the_inbox(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 09:00'));
        Http::preventStrayRequests();
        Http::fake(['ha.example.com/api/services/*' => Http::response([])]);
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::FINANCE => true, Addons::SMART_HOME => true]])->save();
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 't']);
        SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'light.wohnzimmer', 'name' => 'Licht', 'area' => 'Wohnzimmer', 'state' => 'off']);
        $this->actingAs($user);

        $this->assertSame('expense', SmartCapture::preview($user, $household->id, '49,90 € Tanken')['kind']);
        $this->assertSame('task', SmartCapture::preview($user, $household->id, 'Zahnarzt Do 10 Uhr')['kind']);
        $this->assertSame('inbox', SmartCapture::preview($user, $household->id, 'Idee: Garten neu planen')['kind']);

        $field = fn (string $text) => Volt::test('quick-capture')->set('title', $text)->call('capture')->assertHasNoErrors();

        $field('Einkauf: Milch, Brot und Eier')->assertDispatched('toast');
        $this->assertEqualsCanonicalizing(['Milch', 'Brot', 'Eier'], ShoppingListEntry::pluck('label')->all());

        $field('Tanken 49,90 €');
        $expense = Transaction::sole();
        $this->assertEquals([-49.9, 'Tanken', '2026-10-05'], [(float) $expense->amount, $expense->counterparty, $expense->booked_on->toDateString()]);

        $field('Zahnarzt Do 10 Uhr');
        $task = Item::where('type', Item::TYPE_TODO)->sole();
        $this->assertSame(['Zahnarzt', '10:00', '2026-10-08'], [$task->title, $task->due_time, $task->dueDate()]);

        $field('Urlaubsmodus an');
        $this->assertTrue(HouseholdMode::where('household_id', $household->id)->where('key', 'vacation')->value('active'));

        $field('Licht Wohnzimmer an');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/homeassistant/turn_on') && $request['entity_id'] === 'light.wohnzimmer');

        $field('Idee: Garten neu planen');
        $this->assertSame('Idee: Garten neu planen', Item::where('type', Item::TYPE_INBOX)->value('title'));

        // The explicit choices still work as before.
        Volt::test('quick-capture')->set('target', 'inbox')->set('title', 'Zahnarzt morgen')->call('capture');
        $this->assertSame(2, Item::where('type', Item::TYPE_INBOX)->count());
    }

    public function test_undo_removes_only_what_the_capture_created(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::FINANCE => true]])->save();
        $this->actingAs($user);
        Volt::test('quick-capture')->set('title', 'Einkauf: Milch')->call('capture');

        $filed = SmartCapture::file($user, $household->id, 'Einkauf: Milch, Käse');
        $this->assertSame(['Milch', 'Käse'], ShoppingListEntry::orderBy('id')->pluck('label')->all());
        Volt::test('quick-capture')->call('undoCapture', $filed['undo']);
        $this->assertSame(['Milch'], ShoppingListEntry::pluck('label')->all());

        $expense = SmartCapture::file($user, $household->id, '12 € Bäcker');
        SmartCapture::undo($user, $household->id, $expense['undo']);
        $this->assertSame(0, Transaction::count());

        $mode = SmartCapture::file($user, $household->id, 'Gästemodus an');
        $this->assertTrue(HouseholdMode::where('household_id', $household->id)->where('key', 'guests')->value('active'));
        SmartCapture::undo($user, $household->id, $mode['undo']);
        $this->assertFalse(HouseholdMode::where('household_id', $household->id)->where('key', 'guests')->value('active'));
    }

    public function test_expenses_need_the_finance_module(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::FINANCE => false]])->save();

        $this->assertNotSame('expense', SmartCapture::preview($user->fresh(), $household->id, '50 € Tanken')['kind']);
    }
}
