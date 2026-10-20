<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\Link;
use App\Models\Recipe;
use App\Support\Links;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class LinksEverywhereTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_texts_link_to_pages_tasks_entries_and_recipes_and_the_targets_show_it(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->todo()->for($household)->create(['title' => 'Handwerker anrufen']);
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Lasagne']);
        Item::factory()->note()->for($household)->create(['title' => 'Garten']);

        $page = Item::factory()->note()->for($household)->create([
            'title' => 'Wochenende',
            'content' => ['body' => 'Im [[Garten]] arbeiten, dann [✅ Handwerker anrufen]('.route('items.show', $task).') und [🍳 Lasagne]('.route('recipes.show', $recipe).') kochen.'],
        ]);

        $this->assertSame(3, Link::where(['from_type' => 'item', 'from_id' => $page->id, 'source' => 'body'])->count());

        // The task and the recipe list the page under "Linked from".
        Volt::test('links-panel', ['type' => 'item', 'id' => $task->id])->assertSee('Wochenende');
        Volt::test('links-panel', ['type' => 'recipe', 'id' => $recipe->id])->assertSee('Wochenende');
        $this->get(route('recipes.show', $recipe))->assertSee('Wochenende');
        $this->get(route('collections.show', ['collection' => $tasks, 'entry' => $task->id]))->assertSee('Wochenende');

        // Removing the link from the text removes it everywhere.
        $page->update(['content' => ['body' => 'Nur noch [[Garten]].']]);
        Volt::test('links-panel', ['type' => 'recipe', 'id' => $recipe->id])->assertDontSee('Wochenende');
    }

    public function test_anything_can_be_linked_with_the_link_button_and_unlinked_from_both_sides(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Apfelkuchen']);
        $page = Item::factory()->note()->for($household)->create(['title' => 'Geburtstag Oma']);

        Volt::test('links-panel', ['type' => 'recipe', 'id' => $recipe->id])
            ->set('adding', true)
            ->set('query', 'geburt')
            ->assertSee('Geburtstag Oma')
            ->call('link', 'item:'.$page->id)
            ->assertSet('adding', false)
            ->assertSee('Geburtstag Oma');

        Volt::test('links-panel', ['type' => 'item', 'id' => $page->id])
            ->assertSee('Apfelkuchen')
            ->call('unlink', 'recipe:'.$recipe->id)
            ->assertDontSee('Apfelkuchen');

        $this->assertSame(0, Link::count());
    }

    public function test_links_respect_privacy_and_households(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$stranger, $otherHousehold] = $this->memberWithHousehold();
        $target = Item::factory()->note()->for($household)->create(['title' => 'Urlaub']);
        $secret = Item::factory()->note()->for($household)->create([
            'title' => 'Geheimer Plan', 'visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $partner->id,
            'content' => ['body' => '[Urlaub]('.route('items.show', $target).')'],
        ]);
        $foreign = Item::factory()->note()->for($otherHousehold)->create(['title' => 'Fremd']);

        $this->actingAs($user);
        Volt::test('links-panel', ['type' => 'item', 'id' => $target->id])->assertDontSee('Geheimer Plan');
        $this->assertSame([], Links::search('geheim', $user, $household->id));
        $this->assertSame([], Links::search('fremd', $user, $household->id));
        Volt::test('links-panel', ['type' => 'item', 'id' => $target->id])->call('link', 'item:'.$foreign->id)->assertNotFound();
        $this->assertSame(0, Link::where('source', 'manual')->count());
        Volt::test('links-panel', ['type' => 'item', 'id' => $secret->id])->assertNotFound();

        $this->actingAs($partner);
        Volt::test('links-panel', ['type' => 'item', 'id' => $target->id])->assertSee('Geheimer Plan');

        $this->actingAs($stranger)->get(route('items.show', $target))->assertNotFound();
    }

    public function test_the_inbox_quick_sheet_embeds_the_links_panel(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $entry = Item::factory()->for($household)->create(['type' => Item::TYPE_INBOX, 'title' => 'Idee notiert']);

        Volt::test('inbox.quick-sheet')
            ->call('open', $entry->id)
            ->assertSee('Idee notiert')
            ->assertSeeLivewire('links-panel');
    }

    public function test_item_links_open_the_right_place(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $page = Item::factory()->note()->for($household)->create();
        $task = Item::factory()->todo()->for($household)->create();
        $inbox = Item::factory()->for($household)->create(['type' => Item::TYPE_INBOX]);

        $this->get(route('items.show', $page))->assertRedirect(route('pages.index', ['item' => $page->id]));
        $this->get(route('items.show', $task))->assertRedirect(route('tasks.index', ['task' => $task->id]));
        $this->get(route('items.show', $inbox))->assertRedirect(route('inbox.index'));
    }

    public function test_multi_select_and_relation_properties(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Projekte')->set('template', 'blank')->call('create');
        $collection = Collection::where('name', 'Projekte')->sole();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Grillfest-Salat']);
        $page = Item::factory()->note()->for($household)->create(['title' => 'Einladungsliste']);

        $component = Volt::test('collections.show', ['collection' => $collection->id])
            ->set('newPropertyName', 'Themen')->set('newPropertyType', CollectionProperty::TYPE_MULTI_SELECT)->call('addProperty')
            ->set('newPropertyName', 'Gehört zu')->set('newPropertyType', CollectionProperty::TYPE_RELATION)->call('addProperty');
        $topics = $collection->properties()->where('name', 'Themen')->sole();
        $relation = $collection->properties()->where('name', 'Gehört zu')->sole();
        $garden = $topics->addOption('Garten');
        $party = $topics->addOption('Feier');

        $component->set('newTitle', 'Sommerfest')->call('addEntry');
        $entry = Item::where('title', 'Sommerfest')->sole();

        $component->call('updateProperty', $entry->id, $topics->id, [$garden['id'], $party['id'], 'unbekannt'])
            ->call('updateProperty', $entry->id, $relation->id, ['recipe:'.$recipe->id, 'item:'.$page->id, 'kaputt']);

        $entry->refresh();
        $this->assertSame([$garden['id'], $party['id']], $entry->propertyValue($topics));
        $this->assertSame(['recipe:'.$recipe->id, 'item:'.$page->id], $entry->propertyValue($relation));
        $this->assertSame(2, Link::where(['from_id' => $entry->id, 'source' => 'property'])->count());

        Volt::test('collections.show', ['collection' => $collection->id, 'viewMode' => 'table'])
            ->assertSee('Garten')->assertSee('Feier')->assertSee('Grillfest-Salat')->assertSee('Einladungsliste');
        Volt::test('links-panel', ['type' => 'recipe', 'id' => $recipe->id])->assertSee('Sommerfest');

        // Deleting an option removes it from multi-select values; deleting the recipe removes its links.
        $component->call('deleteOption', $topics->id, $garden['id']);
        $this->assertSame([$party['id']], $entry->fresh()->propertyValue($topics));
        $recipe->delete();
        $this->assertSame(1, Link::where(['from_id' => $entry->id])->count());
    }

    public function test_editor_link_suggestions_find_everything_visible(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Item::factory()->todo()->for($household)->create(['title' => 'Steuer machen']);
        Recipe::factory()->for($household)->create(['title' => 'Steuerberater-Kekse']);

        $results = Volt::test('pages.index')->call('linkTargets', 'steuer')->effects['returns'][0];

        $this->assertEqualsCanonicalizing(['Steuer machen', 'Steuerberater-Kekse'], array_column($results, 'title'));
        $this->assertSame('Steuer machen', $results[0]['title']);
    }
}
