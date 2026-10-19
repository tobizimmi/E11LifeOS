<?php

namespace Tests\Feature;

use App\Models\PaperlessInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DocumentArchiveTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function fakePaperless(): void
    {
        Http::fake([
            'paperless.test/api/tags/?*' => Http::response(['results' => [['id' => 3, 'name' => 'Steuer', 'is_inbox_tag' => false]]]),
            'paperless.test/api/correspondents/?*' => Http::response(['results' => [['id' => 5, 'name' => 'Finanzamt']]]),
            'paperless.test/api/document_types/?*' => Http::response(['results' => [['id' => 9, 'name' => 'Rechnung']]]),
            'paperless.test/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Steuerbescheid 2025', 'created' => '2026-03-10', 'correspondent' => 5, 'document_type' => 9, 'tags' => [3], 'notes' => []]),
            'paperless.test/api/documents/?*' => Http::response(['count' => 1, 'results' => [
                ['id' => 41, 'title' => 'Steuerbescheid 2025', 'created' => '2026-03-10T00:00:00Z', 'correspondent' => 5, 'document_type' => 9, 'tags' => [3]],
            ]]),
        ]);
    }

    public function test_the_archive_lists_filters_edits_and_deletes_documents(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret', 'shared' => false]);
        $this->actingAs($user);

        $component = Volt::test('documents.index')
            ->assertSee('Steuerbescheid 2025')
            ->assertSee('Finanzamt')
            ->call('open', 41)
            ->assertSet('editTitle', 'Steuerbescheid 2025')
            ->assertSet('editCorrespondent', 5)
            ->assertSet('editDocumentType', 9)
            ->set('editTitle', 'Steuerbescheid 2025 (geprüft)')
            ->call('saveMeta');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/api/documents/41/')
            && $request['title'] === 'Steuerbescheid 2025 (geprüft)'
            && $request['correspondent'] === 5);

        $component->call('deleteDocument', 41)->assertSet('selectedId', null);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/api/documents/41/'));
    }

    public function test_the_archive_serves_thumbnails_without_requiring_a_link(): void
    {
        Http::fake([
            'paperless.test/api/documents/41/thumb/' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
            'paperless.test/*' => Http::response(['count' => 0, 'results' => []]),
        ]);

        [$user, $household] = $this->memberWithHousehold();
        $instance = PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret', 'shared' => false]);
        $this->actingAs($user);

        // Works for an unlinked document, because access is bound to the usable instance.
        $this->get(route('paperless.archive', [$instance->id, 41, 'thumb']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // A member of another household may not use this instance.
        [$outsider] = $this->memberWithHousehold();
        $this->actingAs($outsider)
            ->get(route('paperless.archive', [$instance->id, 41, 'thumb']))
            ->assertNotFound();
    }
}
