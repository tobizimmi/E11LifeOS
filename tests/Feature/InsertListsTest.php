<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\Collection;
use App\Models\CollectionView;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\NoteMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class InsertListsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_packing_lists_templates_and_shopping_lists_go_into_pages_as_checklists(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $list = ShoppingList::create(['household_id' => $household->id, 'name' => 'Drogerie', 'icon' => '🧴']);
        ShoppingListEntry::create(['household_id' => $household->id, 'shopping_list_id' => $list->id, 'label' => 'Zahnpasta', 'quantity' => '2']);
        ShoppingListEntry::create(['household_id' => $household->id, 'shopping_list_id' => $list->id, 'label' => 'Seife', 'checked' => true]);
        ChecklistTemplate::create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Wochenende Zelten', 'visibility' => Item::VISIBILITY_SHARED, 'tasks' => [], 'packing' => [['Zelt', 'other'], ['Ausweis', 'documents']]]);

        $page = Volt::test('pages.index');
        $options = collect($page->instance()->insertableLists());
        $this->assertTrue($options->contains(fn ($option) => $option['name'] === 'Drogerie' && $option['count'] === 1 && $option['live']));
        $this->assertTrue($options->contains('name', 'Wochenende Zelten'));
        $this->assertTrue($options->contains('key', 'packing:beach'));

        $markdown = $page->instance()->listMarkdown('packing:beach');
        $this->assertStringStartsWith('### 🏖️ Strand', $markdown);
        $this->assertStringContainsString("**👕 Kleidung**\n\n- [ ] Badesachen", $markdown);

        $own = $page->instance()->listMarkdown('template:'.ChecklistTemplate::sole()->id);
        $this->assertTrue(strpos($own, '- [ ] Ausweis') < strpos($own, '- [ ] Zelt'));
        $this->assertSame("### 🧴 Drogerie\n\n- [ ] 2 Zahnpasta", $page->instance()->listMarkdown('shopping:'.$list->id));
        $this->assertSame(':::embed {type="shopping" list="'.$list->id.'"} :::', $page->instance()->listMarkdown('shopping:'.$list->id, true));

        // The live block shows the current open entries of the household's list only.
        $note = Item::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'type' => Item::TYPE_NOTE, 'title' => 'Wochenende', 'content' => ['body' => ':::embed {type="shopping" list="'.$list->id.'"} :::']]);
        $html = NoteMarkdown::toHtml($note, $user);
        $this->assertStringContainsString('Zahnpasta', $html);
        $this->assertStringNotContainsString('Seife', $html);

        [$stranger, $otherHousehold] = $this->memberWithHousehold();
        $foreign = Item::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $stranger->id, 'type' => Item::TYPE_NOTE, 'title' => 'Fremd', 'content' => ['body' => ':::embed {type="shopping" list="'.$list->id.'"} :::']]);
        $this->assertStringNotContainsString('Zahnpasta', NoteMarkdown::toHtml($foreign, $stranger));

        $this->actingAs($stranger);
        Volt::test('pages.index')->call('listMarkdown', 'shopping:'.$list->id)->assertNotFound();
    }

    public function test_collections_and_their_saved_views_can_be_embedded_from_the_picker(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        // A regular collection has no system_key at all (null), not an empty string — a plain
        // where('system_key', '!=', ...) would silently drop it, since SQL's "!=" never matches
        // a null column. This must not happen: every normal collection has to show up here.
        $collection = Collection::factory()->for($household)->create(['name' => 'Verträge', 'icon' => '📑']);
        $view = CollectionView::create(['collection_id' => $collection->id, 'name' => 'Offene', 'settings' => []]);
        $tasks = Collection::tasksFor($household->id);

        $page = Volt::test('pages.index');
        $options = collect($page->instance()->insertableLists());

        $this->assertTrue($options->contains(fn ($option) => $option['key'] === 'collection:'.$collection->id && $option['name'] === 'Verträge' && $option['live']));
        $this->assertTrue($options->contains('key', 'collection:'.$collection->id.'-'.$view->id));
        // The built-in Tasks collection has its own dedicated "tasks" embed already.
        $this->assertFalse($options->contains('key', 'collection:'.$tasks->id));

        $this->assertSame(':::embed {type="collection" collection="'.$collection->id.'"} :::', $page->instance()->listMarkdown('collection:'.$collection->id, true));
        $this->assertSame(':::embed {type="collection" collection="'.$collection->id.'" view="'.$view->id.'"} :::', $page->instance()->listMarkdown('collection:'.$collection->id.'-'.$view->id, true));
    }

    public function test_a_list_becomes_subtasks_of_a_task_without_duplicates(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => 'Koffer packen']);

        Volt::test('tasks.index')->call('openTask', $task->id)
            ->assertSee('Liste als Unteraufgaben einfügen')
            ->call('addListAsSubtasks', $task->id, 'packing:city')
            ->call('addListAsSubtasks', $task->id, 'packing:city')
            ->call('addListAsSubtasks', $task->id, 'packing:unknown');

        $subtasks = collect($task->fresh()->subtasks())->pluck('text');
        $this->assertCount(5, $subtasks);
        $this->assertContains('Regenschirm', $subtasks);
    }
}
