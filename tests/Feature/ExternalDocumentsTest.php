<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\ExternalLink;
use App\Models\Item;
use App\Models\PaperlessInstance;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ExternalDocumentsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function fakePaperless(): void
    {
        Http::fake([
            'paperless.test/api/documents/?*' => Http::response(['count' => 2, 'results' => [
                ['id' => 41, 'title' => 'Kaufvertrag Lindenstraße', 'created' => '2026-07-01T00:00:00Z', 'correspondent' => 3],
                ['id' => 42, 'title' => 'Grundbuchauszug', 'created' => '2026-06-15T00:00:00Z', 'correspondent' => null],
            ]]),
            'paperless.test/api/documents/41/thumb/' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/webp']),
            'paperless.test/api/documents/41/preview/' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
            'paperless.test/api/documents/41/notes/' => Http::response([]),
            'paperless.test/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Kaufvertrag Lindenstraße', 'created' => '2026-07-01', 'tags' => [1], 'notes' => []]),
            'paperless.test/api/documents/post_document/' => Http::response('"b1e2-task"'),
            'paperless.test/api/tasks/?*' => Http::response([['status' => 'SUCCESS', 'related_document' => 77]]),
            'paperless.test/api/tags/?*' => Http::response(['results' => [['id' => 1, 'name' => 'Haus'], ['id' => 2, 'name' => 'Steuer']]]),
        ]);
    }

    private function paperlessInstance(int $userId, int $householdId, bool $shared = false): PaperlessInstance
    {
        return PaperlessInstance::create(['user_id' => $userId, 'household_id' => $householdId, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token', 'shared' => $shared]);
    }

    private function entry(Collection $collection, int $userId): Item
    {
        return Item::create(['household_id' => $collection->household_id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Hausratversicherung', 'visibility' => Item::VISIBILITY_SHARED, 'created_by' => $userId]);
    }

    public function test_an_instance_is_connected_after_a_successful_test_and_is_personal_or_shared(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($user);

        Volt::test('profile.paperless-form')
            ->call('add')
            ->set('name', 'Familienarchiv')->set('url', 'https://paperless.test/')->set('token', 'secret-token')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Verbunden – 2 Dokumente in paperless.');

        $instance = PaperlessInstance::sole();
        $this->assertSame(['Familienarchiv', 'https://paperless.test', 'secret-token', false], [$instance->name, $instance->url, $instance->token, $instance->shared]);
        $this->assertNotSame('secret-token', \DB::table('paperless_instances')->value('token'));
        $this->assertArrayNotHasKey('token', $instance->toArray());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token secret-token'));

        // Personal: the partner does not get it; shared with the household: they do.
        $this->assertTrue($user->hasPaperless());
        $this->assertFalse($partner->hasPaperless());
        Volt::test('profile.paperless-form')->call('toggleShared', $instance->id);
        $this->assertTrue($partner->fresh()->hasPaperless());

        $this->actingAs($partner);
        Volt::test('profile.paperless-form')->assertSee('geteilt von')->call('toggleShared', $instance->id)->assertNotFound();
        Volt::test('profile.paperless-form')->call('remove', $instance->id)->assertNotFound();
        [$stranger] = $this->memberWithHousehold();
        $this->assertFalse($stranger->hasPaperless());

        $this->actingAs($user);
        Volt::test('profile.paperless-form')->call('add')->set('url', 'http://127.0.0.1:8000')->set('token', 'x')->call('save')->assertHasErrors('url');
        $this->assertNull(PaperlessClient::normaliseUrl('file:///etc/passwd'));
        $this->assertNull(PaperlessClient::normaliseUrl('https://user:pw@paperless.test'));
    }

    public function test_documents_are_searched_linked_previewed_edited_and_uploaded(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $instance = $this->paperlessInstance($user->id, $household->id);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        $this->actingAs($user);

        $panel = Volt::test('documents-panel', ['type' => 'real_estate', 'id' => $realEstate->id])
            ->call('open', 'paperless')
            ->set('query', 'kaufvertrag')
            ->assertSee('Grundbuchauszug')
            ->call('linkPaperless', 41, 'Kaufvertrag Lindenstraße')
            ->assertSee('Archiv #41');

        $link = ExternalLink::sole();
        $this->assertSame([ExternalLink::PAPERLESS, $instance->id, 41, 'real_estate', $realEstate->id], [$link->kind, $link->paperless_instance_id, $link->paperless_document_id, $link->linkable_type, $link->linkable_id]);

        $this->get(route('paperless.document', [$instance->id, 41, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('paperless.document', [$instance->id, 41, 'preview']))->assertOk()->assertHeader('Content-Disposition', 'inline');

        $panel->call('editDocument', $link->id)->assertSee('Steuer')
            ->set('editTitle', 'Kaufvertrag (notariell)')->set('newTag', '2')->set('note', 'Original im Ordner')
            ->call('saveDocument')->assertSee('In paperless gespeichert.');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['title'] === 'Kaufvertrag (notariell)' && $request['tags'] === [1, 2]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/notes/') && $request['note'] === 'Original im Ordner');
        $this->assertSame('Kaufvertrag (notariell)', $link->fresh()->title);

        $panel->call('open', 'upload')->set('upload', UploadedFile::fake()->create('angebot.pdf', 50, 'application/pdf'))->set('uploadTitle', 'Angebot Dach')->call('uploadDocument')->assertHasNoErrors();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/post_document/') && $request->isMultipart());
        Storage::disk('local')->assertMissing('external/'.$household->id);

        // As soon as paperless has processed the upload (task SUCCESS), the document id is filled in.
        $uploaded = ExternalLink::where('title', 'Angebot Dach')->sole();
        $this->assertSame([77, null, $instance->id], [$uploaded->paperless_document_id, $uploaded->paperless_task_id, $uploaded->paperless_instance_id]);
    }

    public function test_mail_links_and_mail_copies_without_paperless(): void
    {
        Storage::fake('local');
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $entry = $this->entry(Collection::tasksFor($household->id), $user->id);

        Volt::test('documents-panel', ['type' => 'item', 'id' => $entry->id])
            ->call('open', 'mail')->set('mailUrl', 'javascript:alert(1)')->call('addMailLink')->assertHasErrors('mailUrl')
            ->set('mailUrl', 'message://%3C1234@mail.example.com%3E')->call('addMailLink')->assertHasNoErrors()
            ->call('open', 'mail')->set('mailUrl', 'https://mail.google.com/mail/u/0/#inbox/FMfcgz')->set('mailTitle', 'Angebot Versicherung')->call('addMailLink')
            ->call('open', 'upload')->set('upload', UploadedFile::fake()->create('rueckfrage.eml', 5, 'message/rfc822'))->call('uploadDocument')->assertHasNoErrors()
            ->assertSee('E-Mail (Apple Mail)')->assertSee('Angebot Versicherung')->assertSee('Gmail')->assertSee('rueckfrage.eml');

        $file = ExternalLink::where('kind', ExternalLink::FILE)->sole();
        Storage::disk('local')->assertExists($file->path);
        $this->get(route('external-links.file', $file))->assertOk();

        $entry->delete();
        $this->assertSame(0, ExternalLink::count());
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_links_follow_the_visibility_of_what_they_belong_to(): void
    {
        Storage::fake('local');
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'visibility' => Item::VISIBILITY_PRIVATE]);
        $invoice = RealEstateInvoice::factory()->create(['real_estate_id' => $private->id]);
        $link = ExternalLink::create(['household_id' => $household->id, 'created_by' => $user->id, 'linkable_type' => 'real_estate_invoice', 'linkable_id' => $invoice->id, 'kind' => 'file', 'title' => 'x.pdf', 'path' => 'external/x.pdf']);
        Storage::disk('local')->put('external/x.pdf', 'x');

        $this->actingAs($partner);
        $this->get(route('external-links.file', $link))->assertNotFound();
        Volt::test('documents-panel', ['type' => 'real_estate_invoice', 'id' => $invoice->id])->assertNotFound();
        // Someone else's personal instance is never used for them.
        $personal = $this->paperlessInstance($user->id, $household->id);
        $this->get(route('paperless.document', [$personal->id, 41, 'thumb']))->assertNotFound();

        $this->actingAs($user)->get(route('external-links.file', $link))->assertOk();
        $private->delete();
        $this->assertSame(0, ExternalLink::count());
    }

    public function test_a_shared_instance_previews_for_the_household_and_personal_links_show_only_the_title(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $shared = $this->paperlessInstance($user->id, $household->id, shared: true);
        $personal = $this->paperlessInstance($user->id, $household->id);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        foreach ([[$shared, 41, 'Kaufvertrag'], [$personal, 42, 'Privates Schreiben']] as [$instance, $id, $title]) {
            ExternalLink::create(['household_id' => $household->id, 'created_by' => $user->id, 'linkable_type' => 'real_estate', 'linkable_id' => $realEstate->id, 'kind' => 'paperless', 'paperless_instance_id' => $instance->id, 'paperless_document_id' => $id, 'title' => $title]);
        }

        $this->actingAs($partner);
        Volt::test('documents-panel', ['type' => 'real_estate', 'id' => $realEstate->id])
            ->assertSee(route('paperless.document', [$shared->id, 41, 'thumb']))
            ->assertDontSee(route('paperless.document', [$personal->id, 42, 'thumb']))
            ->assertSee('Privates Schreiben')->assertSee('kein Zugriff')
            ->assertSet('instanceId', $shared->id);
        $this->get(route('paperless.document', [$shared->id, 41, 'thumb']))->assertOk();
        $this->get(route('paperless.document', [$personal->id, 42, 'thumb']))->assertNotFound();
    }

    public function test_an_unreachable_paperless_in_the_home_network_points_to_the_vpn(): void
    {
        Http::fake(['192.168.10.210:2212/*' => Http::failedConnection('cURL error 28: Connection timed out after 10000 milliseconds')]);
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.paperless-form')
            ->call('add')
            ->set('name', 'Zuhause')->set('url', 'http://192.168.10.210:2212')->set('token', 'secret-token')
            ->call('save')
            ->assertSee('192.168.10.210 liegt in einem privaten Netz, geht aber nicht durchs VPN')
            ->assertDontSee('Adresse und Token prüfen');

        $this->assertSame(0, PaperlessInstance::count());
    }

    public function test_an_older_paperless_without_the_api_version_is_asked_without_it(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->header('Accept')[0] ?? '', 'version=')
            ? Http::response(['detail' => 'Invalid version in "Accept" header.'], 406)
            : Http::response(['count' => 7, 'results' => []]));
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.paperless-form')
            ->call('add')
            ->set('name', 'Zuhause')->set('url', 'https://paperless.test')->set('token', 'secret-token')
            ->call('save')
            ->assertSee('Verbunden – 7 Dokumente in paperless.');

        // Remembered: the next request goes out without a version right away.
        Http::fake(fn (Request $request) => Http::response(['count' => 7, 'results' => []]));
        (new PaperlessClient('https://paperless.test', 'secret-token'))->count();
        Http::assertSent(fn ($request) => ($request->header('Accept')[0] ?? '') === 'application/json');
    }
}
