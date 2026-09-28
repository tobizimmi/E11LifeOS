<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\ExternalLink;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function entry(Collection $collection, int $userId): Item
    {
        return Item::create(['household_id' => $collection->household_id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Hausratversicherung', 'visibility' => Item::VISIBILITY_SHARED, 'created_by' => $userId]);
    }

    public function test_paperless_is_connected_after_a_successful_test_and_the_token_stays_secret(): void
    {
        $this->fakePaperless();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.paperless-form')
            ->set('url', 'https://paperless.test/')->set('token', 'secret-token')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Verbunden – 2 Dokumente in paperless.');

        $user->refresh();
        $this->assertSame(['https://paperless.test', 'secret-token'], [$user->paperless_url, $user->paperless_token]);
        $this->assertNotSame('secret-token', \DB::table('users')->where('id', $user->id)->value('paperless_token'));
        $this->assertArrayNotHasKey('paperless_token', $user->toArray());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token secret-token'));

        Volt::test('profile.paperless-form')->set('url', 'http://127.0.0.1:8000')->call('save')->assertHasErrors('url');
        $this->assertNull(PaperlessClient::normaliseUrl('file:///etc/passwd'));
        $this->assertNull(PaperlessClient::normaliseUrl('https://user:pw@paperless.test'));
    }

    public function test_documents_are_searched_linked_previewed_edited_and_uploaded(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['paperless_url' => 'https://paperless.test', 'paperless_token' => 'secret-token']);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        $this->actingAs($user);

        $panel = Volt::test('documents-panel', ['type' => 'real_estate', 'id' => $realEstate->id])
            ->call('open', 'paperless')
            ->set('query', 'kaufvertrag')
            ->assertSee('Grundbuchauszug')
            ->call('linkPaperless', 41, 'Kaufvertrag Lindenstraße')
            ->assertSee('paperless #41');

        $link = ExternalLink::sole();
        $this->assertSame([ExternalLink::PAPERLESS, 41, 'real_estate', $realEstate->id], [$link->kind, $link->paperless_document_id, $link->linkable_type, $link->linkable_id]);

        $this->get(route('paperless.document', [41, 'thumb']))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('paperless.document', [41, 'preview']))->assertOk()->assertHeader('Content-Disposition', 'inline');

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
        $this->assertSame([77, null], [$uploaded->paperless_document_id, $uploaded->paperless_task_id]);
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
        // Without paperless access of their own, nothing is proxied.
        $this->get(route('paperless.document', [41, 'thumb']))->assertNotFound();

        $this->actingAs($user)->get(route('external-links.file', $link))->assertOk();
        $private->delete();
        $this->assertSame(0, ExternalLink::count());
    }
}
