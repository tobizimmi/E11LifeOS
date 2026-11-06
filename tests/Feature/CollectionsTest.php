<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_collection_can_be_created_from_a_template(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('collections.index')
            ->set('name', 'Verträge')
            ->set('template', 'contracts')
            ->set('isPrivate', true)
            ->call('create');

        $collection = Collection::where('name', 'Verträge')->firstOrFail();
        $component->assertRedirect(route('collections.show', $collection));

        $this->assertSame($household->id, $collection->household_id);
        $this->assertTrue($collection->isPrivate());
        $this->assertSame('📑', $collection->icon);
        $this->assertSame(
            [CollectionProperty::TYPE_SELECT, CollectionProperty::TYPE_TEXT, CollectionProperty::TYPE_NUMBER, CollectionProperty::TYPE_TEXT, CollectionProperty::TYPE_TEXT, CollectionProperty::TYPE_DATE, CollectionProperty::TYPE_DATE, CollectionProperty::TYPE_URL],
            $collection->properties->pluck('type')->all(),
        );
    }

    public function test_entries_can_be_added_and_property_values_are_validated(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($user);

        $collection = Collection::factory()->for($household)->create();
        $properties = collect(CollectionProperty::TYPES)->mapWithKeys(fn (string $type, int $position) => [
            $type => $collection->properties()->create([
                'name' => $type,
                'type' => $type,
                'options' => $type === CollectionProperty::TYPE_SELECT ? [['id' => 'a', 'name' => 'A', 'color' => 'blue']] : null,
                'position' => $position,
            ]),
        ]);

        $component = Volt::test('collections.show', ['collection' => $collection->id])
            ->set('newTitle', 'Stromvertrag')
            ->call('addEntry');

        $entry = Item::where('title', 'Stromvertrag')->firstOrFail();
        $this->assertSame(Item::TYPE_ENTRY, $entry->type);
        $this->assertSame($collection->id, $entry->collection_id);

        $valid = [
            'text' => [' Stadtwerke ', 'Stadtwerke'],
            'number' => ['42.5', 42.5],
            'date' => ['2027-01-31', '2027-01-31'],
            'select' => ['a', 'a'],
            'checkbox' => [true, true],
            'person' => [(string) $user->id, $user->id],
            'url' => ['https://example.org', 'https://example.org'],
        ];

        foreach ($valid as $type => [$input, $expected]) {
            $component->call('updateProperty', $entry->id, $properties[$type]->id, $input);
            $this->assertSame($expected, $entry->fresh()->propertyValue($properties[$type]), $type);
        }

        $invalid = [
            'number' => 'viel',
            'date' => '2027-02-30',
            'select' => 'gibt-es-nicht',
            'checkbox' => false,
            'person' => (string) $stranger->id,
            'url' => 'javascript:alert(1)',
            'text' => '',
        ];

        foreach ($invalid as $type => $input) {
            $component->call('updateProperty', $entry->id, $properties[$type]->id, $input);
            $this->assertNull($entry->fresh()->propertyValue($properties[$type]), $type);
        }
    }

    public function test_the_tasks_collection_is_built_in_and_keeps_status_and_done_in_sync(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $todo = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_TODO, 'title' => 'Fenster putzen', 'created_by' => $user->id]);

        $tasks = Collection::tasksFor($household->id);
        $status = $tasks->systemProperty('status');
        $this->assertTrue($tasks->isTasks());
        $this->assertSame($tasks->id, $todo->collection_id);
        $this->assertSame(Collection::TASK_STATUS_TODO, $todo->propertyValue($status));
        $this->assertSame(['status', 'due', 'assignee', 'repeat', 'priority', 'category'], $tasks->properties->pluck('system_key')->all());

        $component = Volt::test('collections.show', ['collection' => $tasks->id]);

        $component->call('updateProperty', $todo->id, $status->id, Collection::TASK_STATUS_DONE);
        $this->assertTrue($todo->fresh()->done);

        $component->call('toggleDone', $todo->id);
        $this->assertFalse($todo->fresh()->done);
        $this->assertSame(Collection::TASK_STATUS_TODO, $todo->fresh()->propertyValue($status));

        $component->call('updateProperty', $todo->id, $status->id, Collection::TASK_STATUS_DOING);
        $this->assertFalse($todo->fresh()->done);

        $component->call('toggleDone', $todo->id);
        $this->assertSame(Collection::TASK_STATUS_DONE, $todo->fresh()->propertyValue($status));
    }

    public function test_new_tasks_get_their_board_column_and_the_tasks_collection_cannot_be_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('addEntry', Collection::TASK_STATUS_DONE, 'Schon erledigt')
            ->call('deleteCollection')
            ->assertForbidden();

        $todo = Item::where('title', 'Schon erledigt')->firstOrFail();
        $this->assertSame(Item::TYPE_TODO, $todo->type);
        $this->assertTrue($todo->done);
        $this->assertDatabaseHas('collections', ['id' => $tasks->id]);
    }

    public function test_cards_can_be_moved_between_board_columns(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $status = $tasks->systemProperty('status');

        $first = Item::factory()->todo()->for($household)->create(['title' => 'Erste', 'position' => 0]);
        $second = Item::factory()->todo()->for($household)->create(['title' => 'Zweite', 'position' => 1]);
        $moving = Item::factory()->todo()->for($household)->create(['title' => 'Wandert', 'position' => 2]);
        foreach ([$first, $second] as $doing) {
            $doing->setPropertyValue($status, Collection::TASK_STATUS_DOING);
            $doing->save();
        }

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('moveCard', $moving->id, Collection::TASK_STATUS_DOING, 1);

        $this->assertSame(Collection::TASK_STATUS_DOING, $moving->fresh()->propertyValue($status));
        $this->assertSame([0, 1, 2], [$first->fresh()->position, $moving->fresh()->position, $second->fresh()->position]);
    }

    public function test_properties_and_options_can_be_managed_but_built_in_ones_are_protected(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        $component = Volt::test('collections.show', ['collection' => $tasks->id])
            ->set('newPropertyName', 'Aufwand')
            ->set('newPropertyType', CollectionProperty::TYPE_SELECT)
            ->call('addProperty');

        $priority = $tasks->properties()->where('name', 'Aufwand')->firstOrFail();
        $component->call('addOption', $priority->id, 'Hoch');
        $optionId = $priority->fresh()->selectOptions()[0]['id'];

        $todo = Item::factory()->todo()->for($household)->create();
        $component->call('updateProperty', $todo->id, $priority->id, $optionId)
            ->call('renameOption', $priority->id, $optionId, 'Sehr hoch');
        $this->assertSame('Sehr hoch', $priority->fresh()->option($optionId)['name']);

        $component->call('deleteOption', $priority->id, $optionId);
        $this->assertNull($todo->fresh()->propertyValue($priority));

        $component->call('deleteProperty', $priority->id);
        $this->assertDatabaseMissing('collection_properties', ['id' => $priority->id]);
    }

    public function test_the_table_offers_inline_add_column_on_a_custom_collection(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::factory()->for($household)->create();

        // The inline "+" add-column control is offered on a non-system collection.
        Volt::test('collections.show', ['collection' => $collection->id])
            ->assertSee(__('Add column'))
            ->set('newPropertyName', 'Priorität')
            ->set('newPropertyType', CollectionProperty::TYPE_SELECT)
            ->call('addProperty');

        $this->assertDatabaseHas('collection_properties', ['collection_id' => $collection->id, 'name' => 'Priorität']);
    }

    public function test_the_entry_detail_shows_created_and_edited_metadata(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::factory()->for($household)->create();
        $entry = Item::factory()->for($household)->create(['collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'created_by' => $user->id, 'title' => 'Datensatz']);

        Volt::test('collections.show', ['collection' => $collection->id])
            ->call('openEntry', $entry->id)
            ->assertSee(__('Created'))
            ->assertSee(__('Last edited'))
            ->assertSee($user->name);
    }

    public function test_built_in_task_properties_cannot_be_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('deleteProperty', $tasks->systemProperty('status')->id)
            ->assertForbidden();

        $this->assertNotNull($tasks->fresh()->systemProperty('status'));
    }

    public function test_deleting_a_custom_collection_deletes_its_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::factory()->for($household)->create();
        $entry = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $collection->id]);

        Volt::test('collections.show', ['collection' => $collection->id])
            ->call('deleteCollection')
            ->assertRedirect(route('collections.index'));

        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseMissing('items', ['id' => $entry->id]);
    }

    public function test_private_collections_and_entries_stay_private(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = Collection::factory()->for($household)->privateTo($owner)->create(['name' => 'Geheimprojekte']);
        $tasks = Collection::tasksFor($household->id);
        $privateTodo = Item::factory()->todo()->for($household)->privateTo($owner)->create(['title' => 'Geschenk kaufen']);

        $this->actingAs($partner);

        $this->get(route('collections.show', $private))->assertNotFound();
        Volt::test('collections.index')->assertDontSee('Geheimprojekte');
        Volt::test('collections.show', ['collection' => $tasks->id])->assertDontSee('Geschenk kaufen');
        $this->assertThrows(fn () => Volt::test('collections.show', ['collection' => $tasks->id])->call('toggleDone', $privateTodo->id), ModelNotFoundException::class);

        $shared = Item::factory()->todo()->for($household)->create(['created_by' => $owner->id]);
        Volt::test('collections.show', ['collection' => $tasks->id])->call('toggleEntryVisibility', $shared->id)->assertForbidden();
    }

    public function test_the_entry_panel_edits_notes_and_ticks_checklists(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $todo = Item::factory()->todo()->for($household)->create();

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('openEntry', $todo->id)
            ->assertSet('openEntryId', (string) $todo->id)
            ->call('editBody')
            ->set('body', "- [ ] Leiter holen\n- [ ] Eimer")
            ->call('saveBody')
            ->assertSeeHtml('wire:click.prevent="toggleTask(1)"')
            ->call('toggleTask', 1);

        $this->assertSame("- [ ] Leiter holen\n- [x] Eimer", $todo->fresh()->body());
    }

    public function test_an_entry_can_be_turned_into_a_task(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $collection = Collection::factory()->for($household)->create(['name' => 'Verträge']);
        $kind = $collection->properties()->create(['name' => 'Art', 'type' => CollectionProperty::TYPE_SELECT, 'options' => [['id' => 'a', 'name' => 'Strom', 'color' => 'blue']], 'position' => 0]);
        $entry = Item::factory()->for($collection)->for($household)->create(['type' => Item::TYPE_ENTRY, 'title' => 'Stromvertrag kündigen']);
        $entry->setPropertyValue($kind, 'a');
        $entry->update(['content' => ['body' => 'Kündigungsfrist beachten']]);

        Volt::test('collections.show', ['collection' => $collection->id])
            ->call('openEntry', $entry->id)
            ->assertSet('openEntryId', (string) $entry->id)
            ->call('convertEntryToTask', $entry->id)
            ->assertSet('openEntryId', '');

        $fresh = $entry->fresh();
        $tasks = Collection::tasksFor($household->id);
        $this->assertSame(Item::TYPE_TODO, $fresh->type);
        $this->assertSame($tasks->id, $fresh->collection_id);
        $this->assertFalse((bool) $fresh->done);
        $this->assertNull($fresh->propertyValue($kind));
        // The entry's body becomes the task's notes, read back the same way (Item::body()).
        $this->assertSame('Kündigungsfrist beachten', $fresh->body());
        $this->assertSame('Stromvertrag kündigen', $fresh->title);

        // It no longer shows in the source collection.
        Volt::test('collections.show', ['collection' => $collection->id])->assertDontSee('Stromvertrag kündigen');
    }

    public function test_the_table_can_be_sorted_by_a_property_with_empty_values_last(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');

        foreach (['Ohne Datum' => null, 'Später' => '2027-05-01', 'Bald' => '2027-01-01'] as $title => $date) {
            $todo = Item::factory()->todo()->for($household)->create(['title' => $title]);
            $todo->setPropertyValue($due, $date);
            $todo->save();
        }

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->set('viewMode', 'table')
            ->call('sortBy', (string) $due->id)
            ->assertViewHas('entries', fn ($entries) => $entries->pluck('title')->all() === ['Bald', 'Später', 'Ohne Datum'])
            ->call('sortBy', (string) $due->id)
            ->assertViewHas('entries', fn ($entries) => $entries->pluck('title')->all() === ['Später', 'Bald', 'Ohne Datum']);
    }

    public function test_the_old_todo_url_redirects_to_the_tasks_collection(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $this->actingAs($user)
            ->get('/todos')
            ->assertRedirect(route('tasks.index'));

        $this->actingAs($user)
            ->get('/todos?view=calendar')
            ->assertRedirect(route('collections.show', ['collection' => Collection::tasksFor($household->id), 'view' => 'calendar']));
    }

    public function test_the_dashboard_lists_overdue_tasks_first(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $due = Collection::tasksFor($household->id)->systemProperty('due');

        Item::factory()->todo()->for($household)->create(['title' => 'Ohne Termin', 'position' => 0]);
        $overdue = Item::factory()->todo()->for($household)->create(['title' => 'Längst fällig', 'position' => 1]);
        $overdue->setPropertyValue($due, now()->subDays(3)->toDateString());
        $overdue->save();

        $this->actingAs($user);

        Volt::test('dashboard')
            ->assertViewHas('overdueCount', 1)
            ->assertViewHas('openTodos', fn ($todos) => $todos->pluck('title')->all() === ['Längst fällig', 'Ohne Termin']);
    }

    public function test_search_finds_collection_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::factory()->for($household)->create();
        $entry = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $collection->id, 'title' => 'Handyvertrag']);

        $this->actingAs($user);

        Volt::test('search-palette')
            ->set('query', 'handy')
            ->assertSee('Handyvertrag')
            ->assertSeeHtml(e(route('collections.show', ['collection' => $collection->id, 'entry' => $entry->id])));
    }

    public function test_deleting_an_account_removes_its_private_collections(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $private = Collection::factory()->for($household)->privateTo($user)->create();
        $shared = Collection::factory()->for($household)->create(['created_by' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('collections', ['id' => $private->id]);
        $this->assertDatabaseHas('collections', ['id' => $shared->id, 'created_by' => null]);
    }
}
