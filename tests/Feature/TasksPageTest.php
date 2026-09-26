<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\TaskLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TasksPageTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function task(Collection $tasks, string $title, array $values = [], array $attributes = []): Item
    {
        $task = Item::factory()->todo()->create($attributes + ['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);

        foreach ($values as $key => $value) {
            $task->setPropertyValue($tasks->systemProperty($key), $value);
        }

        $task->save();

        return $task;
    }

    public function test_a_quick_entry_creates_a_task_with_date_priority_repetition_assignee_and_tags(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $partner->update(['name' => 'Anna Schmidt']);
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('tasks.index')
            ->call('previewTask', 'Müll rausbringen jeden Dienstag !hoch @anna #haushalt')
            ->assertSee('Jeden Dienstag')
            ->call('addTask', 'Müll rausbringen jeden Dienstag !hoch @anna #haushalt')
            ->assertHasNoErrors()
            ->assertReturned(true)
            ->assertSet('draft', '');

        $task = Item::where('title', 'Müll rausbringen')->sole();
        $this->assertSame(Item::TYPE_TODO, $task->type);
        $this->assertSame('2026-09-29', $task->propertyValue($tasks->systemProperty('due')));
        $this->assertSame('weekly', $task->propertyValue($tasks->systemProperty('repeat')));
        $this->assertSame('high', $task->propertyValue($tasks->systemProperty('priority')));
        $this->assertSame($partner->id, (int) $task->propertyValue($tasks->systemProperty('assignee')));
        $this->assertSame(['haushalt'], $task->tags->pluck('name')->all());
    }

    public function test_a_task_added_in_today_without_a_date_is_due_today_and_ignored_phrases_stay_in_the_title(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('tasks.index')
            ->call('ignorePhrase', 'Morgen')
            ->call('addTask', 'Morgen-Magazin lesen');

        $task = Item::sole();
        $this->assertSame('Morgen-Magazin lesen', $task->title);
        $this->assertSame('2026-09-25', $task->propertyValue($tasks->systemProperty('due')));

        Volt::test('tasks.index')->call('showList', TaskLists::ALL)->call('addTask', 'Irgendwann aufräumen')->call('addTask', '   ')->assertHasErrors('newTask');
        $this->assertNull(Item::where('title', 'Irgendwann aufräumen')->sole()->propertyValue($tasks->systemProperty('due')));
    }

    public function test_smart_lists_group_tasks_and_only_show_what_the_member_may_see(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $tasks = Collection::tasksFor($household->id);

        $this->task($tasks, 'Überfällig', ['due' => '2026-09-20'], ['created_by' => $user->id]);
        $this->task($tasks, 'Heute fällig', ['due' => '2026-09-25', 'assignee' => $partner->id], ['created_by' => $user->id]);
        $this->task($tasks, 'Nächste Woche', ['due' => '2026-09-30'], ['created_by' => $user->id]);
        $this->task($tasks, 'Ohne Datum', [], ['created_by' => $partner->id]);
        $this->task($tasks, 'Längst erledigt', [], ['done' => true, 'created_by' => $user->id]);
        $this->task($tasks, 'Geheim vom Partner', ['due' => '2026-09-25'], ['created_by' => $partner->id, 'visibility' => Item::VISIBILITY_PRIVATE]);
        $this->actingAs($user);

        $titles = fn (string $list) => fn (array $groups) => collect($groups)->mapWithKeys(fn (array $group) => [$group['key'] => array_map(fn (Item $task) => $task->title, $group['tasks'])])->all();

        Volt::test('tasks.index')
            ->assertViewHas('groups', fn (array $groups) => $titles('')($groups) === ['overdue' => ['Überfällig'], 'today' => ['Heute fällig']])
            ->assertViewHas('counts', [TaskLists::TODAY => 2, TaskLists::UPCOMING => 1, TaskLists::ALL => 4, TaskLists::MINE => 2])
            ->assertDontSee('Geheim vom Partner')
            ->call('showList', TaskLists::UPCOMING)
            ->assertViewHas('groups', fn (array $groups) => $titles('')($groups) === ['day-2026-09-30' => ['Nächste Woche']])
            ->call('showList', TaskLists::ALL)
            ->assertViewHas('groups', fn (array $groups) => array_keys($titles('')($groups)) === ['overdue', 'today', 'week', 'none'])
            ->call('showList', TaskLists::MINE)
            ->assertViewHas('groups', fn (array $groups) => array_merge(...array_values($titles('')($groups))) === ['Überfällig', 'Nächste Woche'])
            ->call('showList', TaskLists::DONE)
            ->assertSee('Längst erledigt');

        $secret = Item::where('title', 'Geheim vom Partner')->sole();
        Volt::test('tasks.index')->call('openTask', $secret->id)->assertNotFound();
    }

    public function test_completing_a_task_can_be_undone(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $task = $this->task(Collection::tasksFor($household->id), 'Blumen gießen', ['due' => '2026-09-25']);

        Volt::test('tasks.index')
            ->call('toggleDone', $task->id)
            ->assertDispatched('toast', action: ['label' => 'Rückgängig', 'event' => 'task-undo', 'params' => ['taskId' => $task->id]]);
        $this->assertTrue($task->fresh()->done);

        Volt::test('tasks.index')->dispatch('task-undo', taskId: $task->id);
        $this->assertFalse($task->fresh()->done);
    }

    public function test_the_detail_panel_edits_date_priority_subtasks_and_notes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $task = $this->task($tasks, 'Umzug planen');

        $component = Volt::test('tasks.index')
            ->call('openTask', $task->id)
            ->call('setDue', $task->id, 'next_week')
            ->call('setPriority', $task->id, 'medium')
            ->call('addSubtask', $task->id, 'Kartons besorgen')
            ->call('addSubtask', $task->id, 'Umzugswagen mieten')
            ->call('saveNotes', $task->id, 'Termin: Anfang Oktober');

        $task->refresh();
        [$first, $second] = $task->subtasks();
        $component->call('toggleSubtask', $task->id, $first['id'])->call('removeSubtask', $task->id, $second['id'])
            ->assertSee('Kartons besorgen');

        $task->refresh();
        $this->assertSame('2026-09-28', $task->propertyValue($tasks->systemProperty('due')));
        $this->assertSame('medium', $task->propertyValue($tasks->systemProperty('priority')));
        $this->assertSame([['id' => $first['id'], 'text' => 'Kartons besorgen', 'done' => true]], $task->subtasks());
        $this->assertSame('Termin: Anfang Oktober', $task->body());

        // Editing the text in the collection view keeps the subtasks.
        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('openEntry', $task->id)->call('editBody')->set('body', 'Neuer Text')->call('saveBody');
        $this->assertCount(1, $task->fresh()->subtasks());
        $this->assertSame('Neuer Text', $task->fresh()->body());
    }

    public function test_several_selected_tasks_can_be_changed_at_once(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [, $otherHousehold] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $first = $this->task($tasks, 'Eins');
        $second = $this->task($tasks, 'Zwei');
        $third = $this->task($tasks, 'Drei');
        $foreign = $this->task(Collection::tasksFor($otherHousehold->id), 'Fremd');

        Volt::test('tasks.index')
            ->call('showList', TaskLists::ALL)
            ->call('toggleSelecting')
            ->call('selectTasks', [$first->id, $second->id, $foreign->id])
            ->call('applyToSelected', 'due:tomorrow')
            ->assertSet('selecting', false)
            ->call('toggleSelecting')
            ->call('selectTasks', [$first->id, $foreign->id])
            ->call('applyToSelected', 'done')
            ->call('toggleSelecting')
            ->call('selectTasks', [$third->id])
            ->call('applyToSelected', 'delete');

        $due = $tasks->systemProperty('due');
        $this->assertSame(['2026-09-26', '2026-09-26'], [$first->fresh()->propertyValue($due), $second->fresh()->propertyValue($due)]);
        $this->assertTrue($first->fresh()->done);
        $this->assertFalse($second->fresh()->done);
        $this->assertModelMissing($third);
        $this->assertFalse($foreign->fresh()->done);
        $this->assertNull($foreign->fresh()->propertyValue(Collection::tasksFor($otherHousehold->id)->systemProperty('due')));
    }

    public function test_links_to_tasks_open_the_tasks_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $task = $this->task(Collection::tasksFor($household->id), 'Steuererklärung');

        $this->actingAs($user)->get(route('items.show', $task))->assertRedirect(route('tasks.index', ['task' => $task->id]));
        $this->actingAs($user)->get(route('tasks.index', ['task' => $task->id]))->assertOk()->assertSee('Steuererklärung')->assertSee('Unteraufgaben');
    }
}
