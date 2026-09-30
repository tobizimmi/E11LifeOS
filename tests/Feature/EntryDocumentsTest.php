<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Collection;
use App\Models\Item;
use App\Support\EntryAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class EntryDocumentsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
        config(['services.anthropic.key' => 'test-key']);
    }

    private function contracts(): Collection
    {
        Volt::test('collections.index')->set('name', 'Verträge')->set('template', 'contracts')->call('create');

        return Collection::where('name', 'Verträge')->sole();
    }

    public function test_documents_are_saved_with_the_form_added_in_the_detail_view_and_deleted(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();

        Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('newEntry')
            ->set('form.title', 'Hausrat')
            ->set('formDocuments', [UploadedFile::fake()->create('police.pdf', 120, 'application/pdf')])
            ->call('saveEntryForm')
            ->assertHasNoErrors();

        $entry = Item::where('title', 'Hausrat')->sole();
        $this->assertSame(['police.pdf'], $entry->attachments->pluck('original_name')->all());

        $component = Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('openEntry', $entry->id)
            ->set('documentUploads', [UploadedFile::fake()->image('beleg.jpg')])
            ->assertHasNoErrors()
            ->assertSee('beleg.jpg')
            ->assertSee('police.pdf');

        $this->assertSame(2, $entry->attachments()->count());
        $police = $entry->attachments()->where('original_name', 'police.pdf')->sole();
        $component->call('deleteDocument', $police->id);
        $this->assertModelMissing($police);
        Storage::disk('local')->assertMissing($police->path);
    }

    public function test_documents_of_other_collections_cannot_be_deleted_here(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $page = Item::factory()->note()->for($household)->create();
        $foreign = Attachment::storeUpload(UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), $household->id, $page->id, $user->id);

        Volt::test('collections.show', ['collection' => $contracts->id])->call('deleteDocument', $foreign->id)->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_the_ai_fills_in_empty_fields_from_a_document(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $provider = $contracts->properties->firstWhere('name', 'Anbieter');
        $cost = $contracts->properties->firstWhere('name', 'Monatliche Kosten (€)');

        $this->mock(EntryAi::class, fn (MockInterface $ai) => $ai->shouldReceive('extract')->once()->andReturn([
            'title' => 'Handyvertrag Telekom',
            'values' => [$provider->id => 'Telekom', $cost->id => 19.99],
            'notes' => 'Kundennummer steht auf Seite 2',
        ]));

        Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('newEntry')
            ->call('fillFormFromDocument')
            ->assertSet('aiFillMessage', 'Füge zuerst ein PDF oder ein Foto des Dokuments hinzu.')
            ->set('form.values.'.$provider->id, 'Schon ausgefüllt')
            ->set('formDocuments', [UploadedFile::fake()->create('vertrag.pdf', 50, 'application/pdf')])
            ->call('fillFormFromDocument')
            ->assertSet('form.title', 'Handyvertrag Telekom')
            ->assertSet('form.values.'.$provider->id, 'Schon ausgefüllt')
            ->assertSet('form.values.'.$cost->id, '19.99')
            ->assertSet('form.body', 'Kundennummer steht auf Seite 2')
            ->assertSee('2 Felder aus dem Dokument ausgefüllt');
    }

    public function test_extracted_values_are_matched_to_the_collection_properties(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts()->load('properties');

        $ai = new class extends EntryAi
        {
            protected function ask(array $content, array $schema, int $maxTokens, ?string $system = null): array
            {
                return ['title' => 'Strom', 'notes' => '', 'fields' => [
                    ['name' => 'kategorie', 'value' => 'strom & gas'],
                    ['name' => 'Monatliche Kosten (€)', 'value' => '78,50 €'],
                    ['name' => 'Kündigen bis', 'value' => '2026-11-30'],
                    ['name' => 'Website', 'value' => 'kein Link'],
                    ['name' => 'Gibt es nicht', 'value' => 'x'],
                ]];
            }
        };

        $result = $ai->extract($contracts, 'pdf', 'application/pdf');
        $byName = collect($result['values'])->mapWithKeys(fn ($value, $id) => [$contracts->properties->firstWhere('id', $id)->name => $value])->all();

        $energy = collect($contracts->properties->firstWhere('name', 'Kategorie')->selectOptions())->firstWhere('name', 'Strom & Gas')['id'];
        $this->assertSame(['Kategorie' => $energy, 'Monatliche Kosten (€)' => 78.5, 'Kündigen bis' => '2026-11-30'], $byName);
    }
}
