<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\CollectionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CollectionReportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('secure');
    }

    /**
     * Builds a collection with a text column and a file/image column.
     *
     * @return array{0: Collection, 1: CollectionProperty, 2: CollectionProperty}
     */
    private function collectionWithFileColumn(int $householdId): array
    {
        $collection = Collection::factory()->create(['household_id' => $householdId, 'name' => 'Projekte']);
        $note = $collection->properties()->create(['name' => 'Notiz', 'type' => CollectionProperty::TYPE_TEXT, 'position' => 0]);
        $files = $collection->properties()->create(['name' => 'Anhänge', 'type' => CollectionProperty::TYPE_FILE, 'position' => 1]);

        return [$collection, $note, $files];
    }

    public function test_file_and_image_values_are_normalised_to_attachment_ids(): void
    {
        $file = new CollectionProperty(['type' => CollectionProperty::TYPE_FILE]);
        $image = new CollectionProperty(['type' => CollectionProperty::TYPE_IMAGE]);

        $this->assertTrue($file->isFiles());
        $this->assertSame([3, 7], $file->normalizeValue(['3', 7, '3', 'x', 0, -2]));
        $this->assertSame([5], $image->normalizeValue(5));
        $this->assertNull($file->normalizeValue([]));
        $this->assertNull($file->normalizeValue('not-a-file'));

        $long = new CollectionProperty(['type' => CollectionProperty::TYPE_LONG_TEXT]);
        $this->assertFalse($long->isFiles());
        $this->assertSame('Zeile 1'."\n".'Zeile 2', $long->normalizeValue('  Zeile 1'."\n".'Zeile 2  '));

        $rating = new CollectionProperty(['type' => CollectionProperty::TYPE_RATING]);
        $this->assertSame(5, $rating->normalizeValue(9));
        $this->assertSame(3, $rating->normalizeValue('3'));
        $this->assertNull($rating->normalizeValue(''));

        $progress = new CollectionProperty(['type' => CollectionProperty::TYPE_PROGRESS]);
        $this->assertSame(0, $progress->normalizeValue('0'));
        $this->assertSame(100, $progress->normalizeValue(140));
        $this->assertSame(60, $progress->normalizeValue(60));
    }

    public function test_the_report_embeds_images_and_lists_other_files(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        [$collection, , $files] = $this->collectionWithFileColumn($household->id);
        $entry = Item::factory()->for($household)->create([
            'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Baustelle', 'created_by' => $user->id,
        ]);
        $image = Attachment::storeUpload(UploadedFile::fake()->image('foto.jpg', 20, 20), $household->id, $entry->id, $user->id);
        $pdf = Attachment::storeUpload(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'), $household->id, $entry->id, $user->id);
        $entry->setPropertyValue($files, [$image->id, $pdf->id]);
        $entry->save();

        $html = CollectionReport::html($collection->fresh('properties'), Item::with('attachments')->where('id', $entry->id)->get(), $collection->properties);

        $this->assertStringContainsString('<img src="data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('plan.pdf', $html);
    }

    public function test_editing_an_entry_with_a_file_column_does_not_error(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        [$collection, $note, $files] = $this->collectionWithFileColumn($household->id);
        $entry = Item::factory()->for($household)->create([
            'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Dach', 'created_by' => $user->id,
        ]);
        $attachment = Attachment::storeUpload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $household->id, $entry->id, $user->id);
        $entry->setPropertyValue($note, 'Hallo');
        $entry->setPropertyValue($files, [$attachment->id]);
        $entry->save();

        Volt::test('collections.show', ['collection' => $collection->id])
            ->call('openEntry', $entry->id)
            ->call('editEntry', $entry->id)
            ->assertHasNoErrors()
            ->assertSet('showEntryForm', true)
            ->set('form.title', 'Dach neu')
            ->call('saveEntryForm')
            ->assertHasNoErrors();

        // The file column keeps its value after editing via the form.
        $this->assertSame([$attachment->id], $entry->fresh()->propertyValue($files));
        $this->assertSame('Dach neu', $entry->fresh()->title);
    }

    public function test_the_report_lists_entries_with_their_values_and_attachment_names(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        [$collection, $note, $files] = $this->collectionWithFileColumn($household->id);

        $entry = Item::factory()->for($household)->create([
            'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Dachsanierung', 'created_by' => $user->id,
        ]);
        $entry->setPropertyValue($note, 'Angebot liegt vor');
        $attachment = Attachment::storeUpload(UploadedFile::fake()->create('angebot.pdf', 20, 'application/pdf'), $household->id, $entry->id, $user->id);
        $entry->setPropertyValue($files, [$attachment->id]);
        $entry->save();

        $html = CollectionReport::html($collection->fresh('properties'), Item::with('attachments')->where('id', $entry->id)->get(), $collection->properties);

        $this->assertStringContainsString('Dachsanierung', $html);
        $this->assertStringContainsString('Angebot liegt vor', $html);
        $this->assertStringContainsString('angebot.pdf', $html);

        $pdf = CollectionReport::pdf($collection, Item::with('attachments')->where('id', $entry->id)->get(), $collection->properties);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_files_can_be_added_to_and_removed_from_a_column_in_the_detail_view(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        [$collection, , $files] = $this->collectionWithFileColumn($household->id);
        $entry = Item::factory()->for($household)->create([
            'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Garten', 'created_by' => $user->id,
        ]);

        $component = Volt::test('collections.show', ['collection' => $collection->id])
            ->call('openEntry', $entry->id)
            ->call('pickColumn', $files->id)
            ->assertSet('columnTargetId', $files->id)
            ->set('columnUpload', [UploadedFile::fake()->create('plan.pdf', 30, 'application/pdf')])
            ->assertHasNoErrors();

        $attachment = $entry->attachments()->sole();
        $this->assertSame('plan.pdf', $attachment->original_name);
        $this->assertSame([$attachment->id], $entry->fresh()->propertyValue($files));

        $component->call('removePropertyFile', $files->id, $attachment->id);
        $this->assertModelMissing($attachment);
        $this->assertNull($entry->fresh()->propertyValue($files));
    }

    public function test_the_report_can_be_downloaded_as_a_pdf(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        [$collection, $note] = $this->collectionWithFileColumn($household->id);
        $entry = Item::factory()->for($household)->create([
            'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Fenster', 'created_by' => $user->id,
        ]);
        $entry->setPropertyValue($note, 'Dreifachverglasung');
        $entry->save();

        Volt::test('collections.show', ['collection' => $collection->id])
            ->call('downloadReport')
            ->assertHasNoErrors()
            ->assertFileDownloaded('projekte.pdf');
    }
}
