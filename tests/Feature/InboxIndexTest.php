<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class InboxIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_quick_capture_adds_an_entry_to_the_inbox(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('quick-capture')
            ->set('title', 'Glühbirnen Flur')
            ->call('capture')
            ->assertSet('title', '')
            ->assertDispatched('item-captured');

        $this->assertDatabaseHas('items', [
            'household_id' => $household->id,
            'type' => Item::TYPE_INBOX,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => 'Glühbirnen Flur',
            'created_by' => $user->id,
        ]);
    }

    public function test_quick_capture_requires_a_title(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('quick-capture')->call('capture')->assertHasErrors(['title' => 'required']);

        $this->assertDatabaseCount('items', 0);
    }

    public function test_an_entry_can_be_turned_into_a_todo_at_the_end_of_the_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Item::factory()->todo()->for($household)->create(['position' => 4]);
        $entry = Item::factory()->inbox()->for($household)->create(['title' => 'Steuer machen']);

        Volt::test('inbox.index')->call('convertToTodo', $entry->id);

        $this->assertDatabaseHas('items', ['id' => $entry->id, 'type' => Item::TYPE_TODO, 'position' => 5, 'done' => false]);
    }

    public function test_an_entry_can_be_turned_into_a_note_and_keeps_its_visibility(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $entry = Item::factory()->inbox()->for($household)->privateTo($user)->create(['title' => 'Idee für Blog']);

        Volt::test('inbox.index')
            ->call('convertToNote', $entry->id)
            ->assertRedirect(route('notes.index', ['note' => $entry->id]));

        $entry->refresh();
        $this->assertSame(Item::TYPE_NOTE, $entry->type);
        $this->assertTrue($entry->isPrivate());
    }

    public function test_an_entry_can_be_moved_to_the_shopping_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $entry = Item::factory()->inbox()->for($household)->create(['title' => 'Hafermilch']);

        Volt::test('inbox.index')->call('moveToShoppingList', $entry->id);

        $this->assertDatabaseMissing('items', ['id' => $entry->id]);
        $this->assertTrue(ShoppingListEntry::where('household_id', $household->id)->where('label', 'Hafermilch')->exists());
    }

    public function test_private_entries_of_other_members_are_hidden(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        Item::factory()->inbox()->for($household)->privateTo($owner)->create(['title' => 'Private Notiz']);
        Item::factory()->inbox()->for($household)->create(['title' => 'Gemeinsamer Gedanke']);

        $this->actingAs($partner);

        Volt::test('inbox.index')
            ->assertSee('Gemeinsamer Gedanke')
            ->assertDontSee('Private Notiz');
    }

    public function test_the_inbox_page_is_reachable_for_members(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->get(route('inbox.index'))->assertOk()->assertSeeVolt('inbox.index');
    }
}
