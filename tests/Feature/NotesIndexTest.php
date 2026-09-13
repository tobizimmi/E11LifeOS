<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NotesIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_create_a_note(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')
            ->set('title', 'Geschenkidee Oma')
            ->set('body', 'Buch über Gartenarbeit')
            ->set('tagsInput', 'geschenk, oma')
            ->call('save');

        $this->assertDatabaseHas('items', [
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Geschenkidee Oma',
            'created_by' => $user->id,
        ]);

        $note = Item::where('title', 'Geschenkidee Oma')->firstOrFail();
        $this->assertSame(['geschenk', 'oma'], $note->tags);
        $this->assertSame('Buch über Gartenarbeit', $note->content['body']);
    }

    public function test_a_member_can_edit_and_delete_their_note(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $note = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Alter Titel',
            'content' => ['body' => 'Alter Inhalt'],
            'tags' => [],
            'created_by' => $user->id,
        ]);

        Volt::test('notes.index')
            ->call('edit', $note->id)
            ->set('title', 'Neuer Titel')
            ->call('save');

        $this->assertDatabaseHas('items', ['id' => $note->id, 'title' => 'Neuer Titel']);

        Volt::test('notes.index')->call('delete', $note->id);

        $this->assertDatabaseMissing('items', ['id' => $note->id]);
    }

    public function test_search_filters_notes_by_title(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Urlaubsideen', 'created_by' => $user->id]);
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkaufsliste Gedanken', 'created_by' => $user->id]);

        $component = Volt::test('notes.index')->set('search', 'Urlaub');

        $component->assertSee('Urlaubsideen');
        $component->assertDontSee('Einkaufsliste Gedanken');
    }

    public function test_a_member_cannot_see_notes_from_another_household(): void
    {
        [$user] = $this->memberWithHousehold();
        [$otherUser, $otherHousehold] = $this->memberWithHousehold();

        Item::create(['household_id' => $otherHousehold->id, 'type' => Item::TYPE_NOTE, 'title' => 'Fremde Notiz', 'created_by' => $otherUser->id]);

        $this->actingAs($user);

        Volt::test('notes.index')->assertDontSee('Fremde Notiz');
    }
}
