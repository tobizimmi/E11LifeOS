<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\Space;
use App\Support\TaskPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SpacesAndTaskCategoriesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_tasks_are_filtered_by_category_and_space_and_categories_can_be_managed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $work = Space::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Arbeit']);

        $component = Volt::test('tasks.index')->call('addTask', 'Steuererklärung')->call('addTask', 'Bericht schreiben');
        $tax = Item::where('title', 'Steuererklärung')->sole();
        $report = Item::where('title', 'Bericht schreiben')->sole();

        $component->call('setCategory', $tax->id, 'finance')->call('setSpace', $report->id, (string) $work->id)
            ->call('filterByCategory', 'finance')->assertSee('Steuererklärung')->assertDontSee('Bericht schreiben')
            ->call('filterByCategory', 'finance')->call('filterBySpace', (string) $work->id)->assertSee('Bericht schreiben')->assertDontSee('Steuererklärung')
            ->call('addTask', 'Präsentation');

        $this->assertSame('finance', $tax->fresh()->propertyValue($tasks->systemProperty('category')));
        $this->assertSame($work->id, Item::where('title', 'Präsentation')->sole()->space_id);

        $component->call('addCategory', 'Verein')->call('removeCategory', 'errands');
        $names = collect($tasks->systemProperty('category')->fresh()->options)->pluck('name')->all();
        $this->assertContains('Verein', $names);
        $this->assertNotContains('Erledigungen', $names);
    }

    public function test_tasks_are_created_and_shown_on_a_page_and_pages_linked_from_a_task(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $space = Space::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);
        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Sommerfest', 'space_id' => $space->id]);

        Volt::test('pages.index', ['item' => $page->id])->set('newTask', 'Grill mieten')->call('addPageTask')->assertSee('Grill mieten');

        $task = Item::where('title', 'Grill mieten')->sole();
        $this->assertSame($space->id, $task->space_id);
        $this->assertSame(['Sommerfest'], TaskPages::pagesOf($task, $user)->pluck('title')->all());

        Volt::test('pages.index', ['item' => $page->id])->call('togglePageTask', $task->id);
        $this->assertTrue($task->fresh()->done);

        $other = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkaufsideen']);
        Volt::test('tasks.index')->set('openTaskId', (string) $task->id)->call('linkPage', $task->id, (string) $other->id)
            ->assertSee('Einkaufsideen')->call('unlinkPage', $task->id, $page->id);
        $this->assertSame(['Einkaufsideen'], TaskPages::pagesOf($task, $user)->pluck('title')->all());

        Volt::test('tasks.index')->call('createPageForTask', $task->id)->assertRedirect();
        $this->assertSame($space->id, Item::where('type', Item::TYPE_NOTE)->where('title', 'Grill mieten')->sole()->space_id);
    }

    public function test_a_space_groups_pages_and_tasks_and_private_spaces_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($user);

        Volt::test('spaces.index')->call('create')->set('form.name', 'Verein')->set('form.icon', '⚽')->call('save')->assertRedirect();
        $space = Space::where('name', 'Verein')->sole();

        Volt::test('pages.index')->call('create')->set('title', 'Vereinsausflug')->set('spaceId', $space->id)->call('save');
        $this->assertSame($space->id, Item::where('title', 'Vereinsausflug')->sole()->space_id);

        Volt::test('spaces.show', ['space' => $space->id])->set('newTask', 'Bus buchen')->call('addTask')
            ->assertSee('Vereinsausflug')->assertSee('Bus buchen');
        $this->assertSame($space->id, Item::where('title', 'Bus buchen')->sole()->space_id);

        $private = Space::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'visibility' => Item::VISIBILITY_PRIVATE]);
        $this->actingAs($partner)->get(route('spaces.show', $private))->assertNotFound();
        $this->get(route('spaces.show', $space))->assertOk()->assertSee('Vereinsausflug');

        Volt::test('pages.index')->call('create')->set('title', 'Test')->set('spaceId', $private->id)->call('save');
        $this->assertNull(Item::where('title', 'Test')->sole()->space_id);

        $this->actingAs($user);
        Volt::test('spaces.index')->call('delete', $space->id);
        $this->assertNull(Item::where('title', 'Vereinsausflug')->sole()->space_id);
    }
}
