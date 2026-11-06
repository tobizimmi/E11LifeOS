<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Support\InsertableLists;
use App\Support\NoteMarkdown;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PagesIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_create_a_database_from_a_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('pages.index')->call('createDatabase', 'projects');

        // The projects template creates a non-system collection with its properties.
        $collection = Collection::where('household_id', $household->id)->whereNull('system_key')->latest('id')->first();
        $this->assertNotNull($collection);
        $this->assertGreaterThanOrEqual(3, $collection->properties()->count());

        // The pantry is never offered inline (it is a system-linked collection).
        $options = InsertableLists::options($user, $household->id, withNewDatabase: true);
        $keys = collect($options)->pluck('key')->all();
        $this->assertContains('newdb:projects', $keys);
        $this->assertContains('newdb:blank', $keys);
        $this->assertNotContains('newdb:pantry', $keys);
    }

    public function test_a_member_can_create_a_note(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('pages.index')
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
        $this->assertSame(['geschenk', 'oma'], $note->tags->pluck('name')->sort()->values()->all());
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
            'created_by' => $user->id,
        ]);

        Volt::test('pages.index')
            ->call('edit', $note->id)
            ->set('title', 'Neuer Titel')
            ->call('save');

        $this->assertDatabaseHas('items', ['id' => $note->id, 'title' => 'Neuer Titel']);

        Volt::test('pages.index')->call('delete', $note->id);

        $this->assertDatabaseMissing('items', ['id' => $note->id]);
    }

    public function test_search_filters_notes_by_title(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Urlaubsideen', 'created_by' => $user->id]);
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkaufsliste Gedanken', 'created_by' => $user->id]);

        $component = Volt::test('pages.index')->set('search', 'Urlaub');

        $component->assertSee('Urlaubsideen');
        $component->assertDontSee('Einkaufsliste Gedanken');
    }

    public function test_a_member_cannot_see_notes_from_another_household(): void
    {
        [$user] = $this->memberWithHousehold();
        [$otherUser, $otherHousehold] = $this->memberWithHousehold();

        Item::create(['household_id' => $otherHousehold->id, 'type' => Item::TYPE_NOTE, 'title' => 'Fremde Notiz', 'created_by' => $otherUser->id]);

        $this->actingAs($user);

        Volt::test('pages.index')->assertDontSee('Fremde Notiz');
    }

    public function test_tags_are_normalized_and_shared_within_the_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('pages.index')->set('title', 'Eins')->set('tagsInput', '#Urlaub, urlaub , Reise')->call('save');
        Volt::test('pages.index')->set('title', 'Zwei')->set('tagsInput', 'URLAUB')->call('save');

        $this->assertSame(['reise', 'urlaub'], Tag::where('household_id', $household->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['reise', 'urlaub'], Item::where('title', 'Eins')->firstOrFail()->tags->pluck('name')->sort()->values()->all());
    }

    public function test_filtering_by_tag_shows_only_tagged_notes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $tagged = Item::factory()->note()->for($household)->create(['title' => 'Strandurlaub']);
        $tagged->syncTagNames(['urlaub']);
        Item::factory()->note()->for($household)->create(['title' => 'Steuererklärung']);

        Volt::test('pages.index')
            ->call('filterByTag', 'urlaub')
            ->assertSee('Strandurlaub')
            ->assertDontSee('Steuererklärung');
    }

    public function test_private_notes_are_only_visible_to_their_creator(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $this->actingAs($owner);
        Volt::test('pages.index')->set('title', 'Geheime Geschenkidee')->set('isPrivate', true)->set('tagsInput', 'überraschung')->call('save');

        $note = Item::where('title', 'Geheime Geschenkidee')->firstOrFail();
        $this->assertTrue($note->isPrivate());

        Volt::test('pages.index')->assertSee('Geheime Geschenkidee');

        $this->actingAs($partner);
        Volt::test('pages.index')
            ->assertDontSee('Geheime Geschenkidee')
            ->assertDontSee('überraschung');

        $this->assertThrows(fn () => Volt::test('pages.index')->call('edit', $note->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => Volt::test('pages.index')->call('delete', $note->id), ModelNotFoundException::class);
        $this->assertDatabaseHas('items', ['id' => $note->id]);
    }

    public function test_only_the_creator_can_change_the_visibility_of_a_note(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $note = Item::factory()->note()->for($household)->create(['created_by' => $owner->id]);

        $this->actingAs($partner);
        Volt::test('pages.index')->call('edit', $note->id)->set('isPrivate', true)->call('save');

        $this->assertFalse($note->fresh()->isPrivate());
    }

    public function test_note_markdown_is_rendered_without_raw_html(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $note = Item::factory()->note()->for($household)->create([
            'title' => 'Formatiert',
            'content' => ['body' => "**Wichtig**\n\n<script>alert('x')</script>\n\n[Klick](javascript:alert(1))"],
        ]);

        Volt::test('pages.index', ['item' => $note->id])
            ->assertSeeHtml('<strong>Wichtig</strong>')
            ->assertDontSeeHtml('<script>alert')
            ->assertDontSeeHtml('javascript:alert');
    }

    public function test_wiki_links_point_to_existing_notes_or_offer_to_create_missing_ones(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $target = Item::factory()->note()->for($household)->create(['title' => 'Rezepte']);
        $source = Item::factory()->note()->for($household)->create([
            'title' => 'Wochenplan',
            'content' => ['body' => 'Siehe [[rezepte|unsere Rezepte]] und [[Urlaub 2027]].'],
        ]);

        Volt::test('pages.index', ['item' => $source->id])
            ->assertSeeHtml('href="'.e(route('pages.index', ['item' => $target->id])).'"')
            ->assertSee('unsere Rezepte')
            ->assertSeeHtml('href="'.e(route('pages.index', ['new' => 'Urlaub 2027'])).'"');
    }

    public function test_wiki_links_do_not_reveal_other_members_private_notes(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $private = Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Tagebuch']);
        $source = Item::factory()->note()->for($household)->create(['content' => ['body' => '[[Tagebuch]]']]);

        $this->actingAs($partner);

        Volt::test('pages.index', ['item' => $source->id])
            ->assertDontSeeHtml('note='.$private->id)
            ->assertSeeHtml('href="'.e(route('pages.index', ['new' => 'Tagebuch'])).'"');
    }

    public function test_a_note_shows_the_notes_linking_to_it(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $target = Item::factory()->note()->for($household)->create(['title' => 'Rezepte']);
        Item::factory()->note()->for($household)->create(['title' => 'Wochenplan', 'content' => ['body' => 'Heute aus [[Rezepte]] kochen']]);
        Item::factory()->note()->for($household)->create(['title' => 'Einkaufsideen', 'content' => ['body' => 'Rezepte ohne Link']]);

        Volt::test('links-panel', ['type' => 'item', 'id' => $target->id])
            ->assertViewHas('linkedFrom', fn (array $nodes) => array_column($nodes, 'title') === ['Wochenplan']);
        $this->get(route('pages.index', ['item' => $target->id]))->assertSee('Wochenplan');
    }

    public function test_a_missing_wiki_link_prefills_the_new_note_form(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)
            ->get(route('pages.index', ['new' => 'Urlaub 2027']))
            ->assertOk()
            ->assertSee('Urlaub 2027');
    }

    public function test_checklist_items_can_be_ticked_off_in_the_read_view(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create([
            'content' => ['body' => "## Einkauf\n\n- [ ] Brot\n- [x] Milch\n\n```\n- [ ] kein Eintrag, nur Code\n```\n\n> - [ ] Im Zitat"],
        ]);

        Volt::test('pages.index', ['item' => $page->id])
            ->assertSeeHtml('wire:click.prevent="toggleTask(0)"')
            ->assertSeeHtml('wire:click.prevent="toggleTask(2)"')
            ->assertDontSeeHtml('toggleTask(3)')
            ->call('toggleTask', 0)
            ->call('toggleTask', 1)
            ->call('toggleTask', 2);

        $this->assertSame(
            "## Einkauf\n\n- [x] Brot\n- [ ] Milch\n\n```\n- [ ] kein Eintrag, nur Code\n```\n\n> - [x] Im Zitat",
            $page->fresh()->body(),
        );
    }

    public function test_checklist_items_show_due_dates_and_people_and_can_become_linked_tasks(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        [$user, $household] = $this->memberWithHousehold();
        $anna = $this->addMemberTo($household);
        $anna->update(['name' => 'Anna']);
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create([
            'content' => ['body' => "- [ ] Angebot einholen //15.10.2026 @Anna\n- [x] Rechnung bezahlt //20.09.\n\n`//01.01. @Code` und tobi@example.com"],
        ]);

        $component = Volt::test('pages.index', ['item' => $page->id])
            ->assertSeeHtml('<span class="checklist-due">📅 15.10.2026</span>')
            ->assertSeeHtml('<span class="checklist-due checklist-due-overdue">📅 20.09.2026</span>')
            ->assertSeeHtml('<span class="checklist-person">@Anna</span>')
            ->assertSeeHtml('<code>//01.01. @Code</code>')
            ->assertSeeHtml('tobi@example.com')
            ->assertSeeHtml('wire:click="checklistToTask(1)"')
            ->call('checklistToTask', 0)
            ->call('checklistToTask', 1);

        $tasks = Collection::tasksFor($household->id);
        [$open, $done] = Item::where('collection_id', $tasks->id)->orderBy('id')->get()->all();
        $this->assertSame('Angebot einholen', $open->title);
        $this->assertSame('2026-10-15', $open->propertyValue($tasks->systemProperty('due')));
        $this->assertSame($anna->id, (int) $open->propertyValue($tasks->systemProperty('assignee')));
        $this->assertFalse((bool) $open->done);
        $this->assertTrue((bool) $done->done);
        $this->assertSame(
            "- [ ] Angebot einholen //15.10.2026 @Anna {task:{$open->id}}\n- [x] Rechnung bezahlt //20.09. {task:{$done->id}}\n\n`//01.01. @Code` und tobi@example.com",
            $page->fresh()->body(),
        );

        // Linked items show a link to their task, and making them a task again does nothing.
        $component->call('checklistToTask', 0)
            ->assertSeeHtml('href="'.e(route('tasks.index', ['task' => $open->id])).'" class="checklist-task"')
            ->assertSeeHtml('class="checklist-task checklist-task-done"')
            ->assertDontSeeHtml('{task:');
        $this->assertSame(2, Item::where('collection_id', $tasks->id)->count());

        // Checklists in collections have no task button (the action exists only on pages).
        $this->assertStringNotContainsString('checklistToTask', NoteMarkdown::toHtml($page->fresh(), $user, interactiveTasks: true));
    }

    public function test_checklist_items_can_be_added_to_the_shopping_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create([
            'content' => ['body' => "- [ ] 2 Liter Milch //15.10.2026 @Anna\n- [ ] Butter, Eier"],
        ]);

        Volt::test('pages.index', ['item' => $page->id])
            ->assertSeeHtml('wire:click="checklistToShopping(0)"')
            ->call('checklistToShopping', 0)
            ->call('checklistToShopping', 1);

        $entries = ShoppingList::defaultFor($household->id)->entries()->orderBy('position')->get();
        // Date and person tokens are stripped (they do not mean anything on a shopping list);
        // the second line's comma-separated items are split like a dictated Siri line.
        $this->assertSame(['Milch', 'Butter', 'Eier'], $entries->pluck('label')->all());
        $this->assertSame('2 Liter', $entries->first()->quantity);
        $this->assertSame("- [x] 2 Liter Milch //15.10.2026 @Anna\n- [x] Butter, Eier", $page->fresh()->body());

        // Already-linked tasks are left alone (nothing to add, no state change).
        $linked = Item::factory()->note()->for($household)->create(['content' => ['body' => '- [ ] Auto zur Inspektion {task:999}']]);
        Volt::test('pages.index', ['item' => $linked->id])
            ->call('checklistToShopping', 0)
            ->assertDispatched('toast', message: 'Dieser Punkt ist schon eine Aufgabe.');
        $this->assertSame('- [ ] Auto zur Inspektion {task:999}', $linked->fresh()->body());
    }

    public function test_a_page_without_subpages_can_be_turned_into_a_task(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create([
            'title' => 'Steuererklärung einreichen',
            'content' => ['body' => 'Belege liegen im Ordner "Steuer 2026"'],
        ]);

        Volt::test('pages.index', ['item' => $page->id])
            ->assertSeeHtml('wire:click="convertToTask('.$page->id.')"')
            ->call('convertToTask', $page->id)
            ->assertRedirect(route('pages.index'));

        $fresh = $page->fresh();
        $tasks = Collection::tasksFor($household->id);
        $this->assertSame(Item::TYPE_TODO, $fresh->type);
        $this->assertSame($tasks->id, $fresh->collection_id);
        $this->assertNull($fresh->parent_id);
        $this->assertSame('Belege liegen im Ordner "Steuer 2026"', $fresh->body());
        $this->assertSame('Steuererklärung einreichen', $fresh->title);
    }

    public function test_a_page_with_subpages_cannot_be_turned_into_a_task(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $parent = Item::factory()->note()->for($household)->create(['title' => 'Umzug']);
        Item::factory()->note()->for($household)->create(['title' => 'Packliste', 'parent_id' => $parent->id]);

        Volt::test('pages.index', ['item' => $parent->id])
            ->assertDontSeeHtml('wire:click="convertToTask('.$parent->id.')"')
            ->call('convertToTask', $parent->id)
            ->assertDispatched('toast', message: 'Diese Seite hat noch Unterseiten – erst verschieben oder löschen.');

        $this->assertSame(Item::TYPE_NOTE, $parent->fresh()->type);
    }

    public function test_a_page_can_be_added_to_the_shopping_list_and_stays_a_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create(['title' => 'Schrauben besorgen']);

        Volt::test('pages.index', ['item' => $page->id])->call('addPageToShoppingList', $page->id);

        $entries = ShoppingList::defaultFor($household->id)->entries;
        $this->assertSame(['Schrauben besorgen'], $entries->pluck('label')->all());
        $this->assertSame(Item::TYPE_NOTE, $page->fresh()->type);
    }

    public function test_ticking_a_checklist_item_that_does_not_exist_changes_nothing(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->note()->for($household)->create(['content' => ['body' => '- [ ] Einzige Aufgabe']]);
        $updatedAt = $page->updated_at;

        $this->travel(1)->minutes();
        Volt::test('pages.index', ['item' => $page->id])->call('toggleTask', 5);

        $this->assertSame('- [ ] Einzige Aufgabe', $page->fresh()->body());
        $this->assertTrue($page->fresh()->updated_at->equalTo($updatedAt));
    }

    public function test_wiki_links_and_markdown_from_the_editor_are_saved_verbatim(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $markdown = "## Planung\n\n- [ ] Erste Aufgabe\n\nSiehe [[Rezepte]] und [[Urlaub|den Urlaub]]\n\n| A | B |\n| --- | --- |\n| 1 | 2 |";

        Volt::test('pages.index')->set('title', 'Aus dem Editor')->set('body', $markdown)->call('save');

        $this->assertSame($markdown, Item::where('title', 'Aus dem Editor')->firstOrFail()->body());
    }

    public function test_opening_a_page_records_a_visit_and_it_appears_under_recently_visited(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $open = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Gerade offen']);
        $earlier = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Vorher besucht']);

        // Visiting the earlier page records it; the overview then lists it (the open page is excluded).
        Volt::test('pages.index', ['item' => $earlier->id]);

        $this->assertDatabaseHas('page_user_states', ['user_id' => $user->id, 'item_id' => $earlier->id]);

        Volt::test('pages.index', ['item' => $open->id])
            ->assertSet('selectedId', $open->id);

        Volt::test('pages.index')
            ->assertSee('Zuletzt besucht')
            ->assertSee('Vorher besucht');
    }

    public function test_a_page_can_be_favourited_and_shows_under_favourites(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Wichtige Seite']);

        Volt::test('pages.index', ['item' => $page->id])->call('toggleFavorite', $page->id);
        $this->assertDatabaseHas('page_user_states', ['user_id' => $user->id, 'item_id' => $page->id, 'favorite' => true]);

        Volt::test('pages.index')->assertSee('Favoriten')->assertSee('Wichtige Seite');

        // Toggling again removes it.
        Volt::test('pages.index', ['item' => $page->id])->call('toggleFavorite', $page->id);
        $this->assertDatabaseHas('page_user_states', ['user_id' => $user->id, 'item_id' => $page->id, 'favorite' => false]);
    }
}
