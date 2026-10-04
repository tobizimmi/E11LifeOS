<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Support\Addons;
use App\Support\Automation\AutomationAi;
use App\Support\Automation\AutomationEngine;
use App\Support\QuickCapture;
use App\Support\SmartHome\EntityStates;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AutomationPlusTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /** @var list<string> title | body of every push */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-05 08:00'));
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->andReturnUsing(function (User $to, string $title, string $body) {
            $this->pushes[] = $title.' | '.$body;

            return 1;
        }));
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array<string, mixed>>  $conditions
     */
    private function automation(User $user, int $householdId, array $trigger, array $conditions = [], ?array $actions = null): Automation
    {
        return Automation::factory()->create([
            'household_id' => $householdId, 'user_id' => $user->id, 'trigger' => $trigger, 'conditions' => $conditions,
            'actions' => $actions ?? [['type' => 'push', 'to' => 'me', 'title' => '{automation}', 'body' => '{reason}', 'urgent' => false]],
        ]);
    }

    public function test_new_triggers_fire_with_the_reason_in_the_message(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $connection = SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://abc.ui.nabu.casa', 'token' => 't']);
        SmartHomeEntity::create(['household_id' => $household->id, 'smart_home_connection_id' => $connection->id, 'entity_id' => 'binary_sensor.haustuer', 'name' => 'Haustür']);

        $this->automation($user, $household->id, ['type' => 'entity_for', 'entity_id' => 'binary_sensor.haustuer', 'value' => 'on', 'minutes' => 10])->update(['name' => 'Tür offen']);
        $this->automation($user, $household->id, ['type' => 'waste', 'keyword' => 'Müll', 'at' => '08:15'])->update(['name' => 'Tonnen']);
        $this->automation($user, $household->id, ['type' => 'birthday', 'days' => 7, 'at' => '08:15'])->update(['name' => 'Geburtstag']);
        $this->automation($user, $household->id, ['type' => 'balance', 'account' => 'Giro', 'operator' => 'lt', 'value' => '500'])->update(['name' => 'Konto']);
        $this->automation($user, $household->id, ['type' => 'mail', 'keyword' => 'paket'])->update(['name' => 'Paket']);

        QuickCapture::task($user, $household->id, 'Restmüll', '2026-10-06');
        Contact::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Oma', 'birthday' => '1950-10-12', 'birth_year_known' => true]);
        BankAccount::create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Girokonto', 'balance' => 320, 'balance_on' => '2026-10-01']);

        EntityStates::remember($connection, 'binary_sensor.haustuer', 'on');
        $this->artisan('automations:run');
        $this->assertSame(['Konto | Saldo „Giro“ < 500 € · beginnt'], $this->pushes);

        // The door has been open for ten minutes; at 08:15 the bins and the birthday come up.
        $this->travelTo(Carbon::parse('2026-10-05 08:15'));
        $this->artisan('automations:run');
        $this->assertContains('Tonnen | Morgen: Restmüll', $this->pushes);
        $this->assertContains('Geburtstag | Geburtstag in 7 Tagen: Oma (76)', $this->pushes);
        $this->assertTrue(collect($this->pushes)->contains(fn (string $push) => str_starts_with($push, 'Tür offen | ')));

        // Each state fires once; a parcel mail runs the mail trigger.
        $count = count($this->pushes);
        $this->travelTo(Carbon::parse('2026-10-05 08:20'));
        $this->artisan('automations:run');
        app(AutomationEngine::class)->mailReceived($household->id, 'Ihr Paket kommt heute', 'dhl@example.com');
        $this->assertSame($count + 1, count($this->pushes));
        $this->assertSame('Paket | Mail von dhl@example.com: Ihr Paket kommt heute', last($this->pushes));
    }

    public function test_or_groups_new_actions_and_the_test_run(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::SMART_HOME => true]])->save();
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://abc.ui.nabu.casa', 'token' => 't']);
        Http::fake(['abc.ui.nabu.casa/*' => Http::response([]), '93.184.216.34/*' => Http::response(['ok' => true])]);
        config(['mail.default' => 'array']);

        $automation = $this->automation($user, $household->id, ['type' => 'manual'], [['type' => 'any', 'conditions' => [
            ['type' => 'mode', 'key' => 'vacation', 'active' => true],
            ['type' => 'time_window', 'from' => '00:00', 'until' => '01:00'],
        ]]], [
            ['type' => 'shopping', 'items' => 'Milch, Brot'],
            ['type' => 'event', 'title' => 'Tonne raus', 'day' => 'tomorrow', 'time' => '19:00'],
            ['type' => 'email', 'to' => 'me', 'subject' => 'Hallo von {automation}', 'body' => '{reason}'],
            ['type' => 'tts', 'media_player' => 'media_player.kueche', 'message' => 'Es ist {time}', 'engine' => 'tts.home_assistant_cloud'],
            ['type' => 'scene_snapshot', 'mode' => 'save', 'scene' => 'Vorher', 'entities' => 'light.flur, light.bad'],
            ['type' => 'webhook', 'url' => 'https://93.184.216.34/hook', 'body' => 'Hi'],
            ['type' => 'webhook', 'url' => 'http://127.0.0.1/admin', 'body' => ''],
        ]);
        $automation->update(['name' => 'Alles']);
        $engine = app(AutomationEngine::class);

        // Neither alternative holds: skipped with the reason.
        $this->assertSame('skipped', $engine->run($automation, 'Test')->status);
        HouseholdMode::forHousehold($household->id)->firstWhere('key', 'vacation')->update(['active' => true]);
        $run = $engine->run($automation->fresh(), 'Test');

        $this->assertSame('partial', $run->status);
        $this->assertStringContainsString('Bedingung erfüllt', $run->log[0]['message']);
        $this->assertFalse(last($run->log)['ok']);
        $this->assertEqualsCanonicalizing(['Milch', 'Brot'], ShoppingListEntry::pluck('label')->all());
        $event = Item::where('title', 'Tonne raus')->sole();
        $this->assertSame('19:00', substr((string) $event->due_time, 0, 5));
        $mail = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Hallo von Alles', $mail->getSubject());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/tts/speak') && $request['message'] === 'Es ist 08:00' && $request['media_player_entity_id'] === 'media_player.kueche');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/services/scene/create') && $request['scene_id'] === 'vorher' && $request['snapshot_entities'] === ['light.flur', 'light.bad']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://93.184.216.34/hook' && $request['automation'] === 'Alles' && $request['reason'] === 'Test');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));

        // The test run in the editor shows what would happen and does nothing.
        $this->actingAs($user);
        $entries = ShoppingListEntry::count();
        Volt::test('automations.index')->call('edit', $automation->id)->call('removeAction', 6)->call('testRun')
            ->assertSee('Probelauf')->assertSee('Würde ausführen: Einkaufsliste: Milch, Brot');
        $this->assertSame($entries, ShoppingListEntry::count());

        // Webhooks to the server's own network are refused in the editor.
        Volt::test('automations.index')->call('edit', $automation->id)->call('save')->assertHasErrors('form.actions.6.url');
    }

    public function test_packages_install_once_and_the_ai_draft_fills_the_editor(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->forceFill(['addons' => [Addons::SMART_HOME => true]])->save();
        $this->actingAs($user);

        Volt::test('automations.index')->call('installPackage', 'security')->call('installPackage', 'security');
        $installed = Automation::where('household_id', $household->id)->get();
        $this->assertCount(4, $installed);
        // The door automation needs a device first.
        $this->assertFalse($installed->firstWhere('trigger.type', 'entity_for')->active);
        $this->assertTrue($installed->firstWhere('trigger.type', 'mail')->active);

        $this->mock(AutomationAi::class, fn (MockInterface $ai) => $ai->shouldReceive('draft')->once()->andReturn(AutomationAi::toForm([
            'name' => 'Abends Licht',
            'trigger' => ['type' => 'sun', 'settings' => [['key' => 'event', 'value' => 'sunset'], ['key' => 'offset', 'value' => '-15']]],
            'conditions' => [['type' => 'teleport', 'settings' => []], ['type' => 'presence', 'settings' => [['key' => 'state', 'value' => 'anyone_home']]]],
            'actions' => [['type' => 'ha_service', 'settings' => [['key' => 'service', 'value' => 'light.turn_on'], ['key' => 'entity_id', 'value' => 'light.wohnzimmer'], ['key' => 'evil', 'value' => 'x']]]],
        ], true)));

        Volt::test('automations.index')->set('draftText', 'Bei Sonnenuntergang Licht an, wenn jemand da ist')->call('draft')
            ->assertSet('editing', true)->assertSet('form.name', 'Abends Licht')
            ->assertSet('form.trigger', ['type' => 'sun', 'event' => 'sunset', 'offset' => -15])
            ->assertSet('form.conditions', [['type' => 'presence', 'state' => 'anyone_home']])
            ->assertSet('form.actions', [['type' => 'ha_service', 'service' => 'light.turn_on', 'entity_id' => 'light.wohnzimmer', 'data' => '']]);
        $this->assertSame(4, Automation::count());

        // The editor offers the description field and builds OR groups.
        config(['services.anthropic.key' => 'test']);
        Volt::test('automations.index')->assertSee('Automation beschreiben')->assertSee('Pakete – mit einem Klick installieren')
            ->call('create')->call('addCondition', 'any')->call('addGroupCondition', 0, 'weather')->call('addGroupCondition', 0, 'presence')
            ->assertSee('Alternative hinzufügen')->call('addGroupCondition', 0, 'any')->assertStatus(422);
    }
}
