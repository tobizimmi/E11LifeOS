<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\PageRevision;
use App\Models\PaperlessInstance;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Trip;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class OrganizationExtrasTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_the_search_finds_trips_people_and_paperless_documents_on_request(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Mallorca', 'destination' => 'Palma']);
        Contact::create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Oma Palmira']);
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token']);
        Http::fake(['paperless.test/api/documents/*' => Http::response(['results' => [['id' => 7, 'title' => 'Mietvertrag Palma', 'created' => '2024-05-01T10:00:00Z']]])]);

        $palette = Volt::test('search-palette')->set('query', 'palm')
            ->assertSee('🧳 Mallorca')
            ->assertSee('👤 Oma Palmira')
            ->assertSee('In paperless-Dokumenten nach „palm“ suchen');
        Http::assertNothingSent();

        $palette->call('searchPaperless')->assertSee('📄 Mietvertrag Palma · 01.05.2024')->assertSee('https://paperless.test/documents/7/details');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'query=palm'));
    }

    public function test_pages_keep_earlier_versions_that_can_be_restored(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $page = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkauf', 'content' => ['body' => 'Version 1'], 'created_by' => $user->id]);

        $component = Volt::test('pages.index', ['item' => $page->id])->call('edit', $page->id);
        $component->call('autosave', 'Einkauf', 'Version 2');
        $component->call('autosave', 'Einkauf', 'Version 3');
        // Typing in one go keeps only the state from before.
        $this->assertSame(['Version 1'], PageRevision::pluck('body')->all());

        $this->travel(15)->minutes();
        $component->call('autosave', 'Einkauf neu', 'Version 4');
        $this->assertSame(['Version 1', 'Version 3'], PageRevision::orderBy('id')->pluck('body')->all());

        Volt::test('pages.index', ['item' => $page->id])->call('showHistory', $page->id)->assertSee('Version 1')
            ->call('restoreRevision', PageRevision::orderBy('id')->first()->id);
        $this->assertSame(['Einkauf', 'Version 1'], [$page->refresh()->title, $page->body()]);
        $this->assertSame('Version 4', PageRevision::latest('id')->first()->body);
    }

    public function test_new_pages_start_from_built_in_or_own_templates(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $own = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Elternabend', 'content' => ['body' => 'Fragen an die Lehrerin {{date}}'], 'created_by' => $user->id]);
        $own->syncTagNames(['vorlage']);

        Volt::test('pages.index')->set('showTemplates', true)->assertSee('Besprechungsnotizen')->assertSee('Elternabend')
            ->call('createFromTemplate', 'meeting')
            ->assertSet('title', 'Besprechungsnotizen 28.09.2026')
            ->assertSet('icon', '🗣️')
            ->call('createFromTemplate', 'page:'.$own->id)
            ->assertSet('body', 'Fragen an die Lehrerin 28.09.2026');
    }

    public function test_pushes_wait_during_quiet_hours_and_come_bundled(): void
    {
        $keys = WebPush::generateKeys();
        config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey'], 'services.webpush.subject' => 'mailto:test@example.com']);
        Http::fake(['push.test/*' => Http::response('', 201)]);
        [$user] = $this->memberWithHousehold();
        $user->forceFill(['push_quiet_from' => 22, 'push_quiet_until' => 7])->save();
        PushSubscription::factory()->for($user)->create(['endpoint' => 'https://push.test/device']);
        $push = app(WebPush::class);

        $this->travelTo(Carbon::parse('2026-09-28 23:30'));
        $push->send($user, 'Budget', 'Lebensmittel 80 %');
        $push->send($user, 'Aufgabe', 'Müll');
        $push->send($user, '💊 Ibuprofen', 'Jetzt', urgent: true);
        $push->send($user, 'Wochenrückblick', '…');
        // Only the urgent one woke the devices.
        Http::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-09-29 07:15'));
        $this->assertSame(1, $push->releaseQuiet());
        $this->assertSame(0, $push->releaseQuiet());

        $messages = $this->actingAs($user)->getJson(route('push.pending'))->json('messages');
        $this->assertCount(1, $messages);
        $this->assertSame('4 neue Benachrichtigungen', $messages[0]['title']);
        $this->assertSame(0, PushMessage::whereNull('delivered_at')->count());
    }
}
