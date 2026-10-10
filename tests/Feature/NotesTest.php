<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_quick_note_is_created_in_the_inbox_and_hidden_from_pages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')
            ->set('draft', "Einkaufen planen\nMontag Wocheneinkauf")
            ->call('addNote')
            ->assertHasNoErrors()
            ->assertSee('Einkaufen planen');

        $note = Item::where('household_id', $household->id)->where('type', Item::TYPE_NOTE)->sole();
        $this->assertSame('Einkaufen planen', $note->title);
        $this->assertSame('Montag Wocheneinkauf', $note->content['body']);

        // The note lives in the hidden system inbox space …
        $inbox = Space::where('household_id', $household->id)->where('system', true)->sole();
        $this->assertSame($inbox->id, $note->space_id);

        // … so the pages overview does not list it yet.
        Volt::test('pages.index')->assertDontSee('Einkaufen planen');
    }

    public function test_a_note_can_be_filed_into_the_pages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('notes.index')->set('draft', 'Projektidee')->call('addNote');
        $note = Item::where('household_id', $household->id)->where('type', Item::TYPE_NOTE)->sole();

        $component->call('fileIntoPages', $note->id);

        // It leaves the inbox and becomes a normal (top-level) page.
        $this->assertNull($note->fresh()->space_id);
        Volt::test('pages.index')->assertSee('Projektidee');
    }

    public function test_an_empty_note_is_ignored(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')->set('draft', '   ')->call('addNote');
        $this->assertSame(0, Item::where('type', Item::TYPE_NOTE)->count());
    }
}
