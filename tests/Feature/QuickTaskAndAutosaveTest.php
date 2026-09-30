<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class QuickTaskAndAutosaveTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_task_opens_in_place_and_changes_save_right_away(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 09:00'));
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => 'Steuererklärung', 'created_by' => $user->id]);

        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk()->assertSee('data-open-task="'.$task->id.'"', false);

        $sheet = Volt::test('tasks.quick-sheet')->dispatch('open-task', id: $task->id)->assertSee('Steuererklärung')->assertDispatched('task-sheet-shown', id: $task->id);
        $sheet->call('setDue', 'tomorrow')->call('setPriority', 'high')->call('saveNotes', 'Belege sammeln')->call('addSubtask', 'Lohnsteuerbescheinigung')
            ->call('updateTitle', 'Steuererklärung 2025')->assertDispatched('task-updated');

        $task->refresh();
        $this->assertSame('Steuererklärung 2025', $task->title);
        $this->assertSame('Belege sammeln', $task->body());
        $this->assertSame('2026-10-01', $task->propertyValue($tasks->systemProperty('due')));
        $this->assertSame('high', $task->propertyValue($tasks->systemProperty('priority')));
        $this->assertSame('Lohnsteuerbescheinigung', $task->subtasks()[0]['text']);

        $sheet->call('toggleDone')->assertSet('taskId', null);
        $this->assertTrue($task->fresh()->done);
    }

    public function test_tasks_of_other_households_do_not_open(): void
    {
        [$user] = $this->memberWithHousehold();
        [, $otherHousehold] = $this->memberWithHousehold();
        $task = Item::factory()->todo()->create(['household_id' => $otherHousehold->id, 'collection_id' => Collection::tasksFor($otherHousehold->id)->id, 'title' => 'Geheim']);

        $this->actingAs($user);
        Volt::test('tasks.quick-sheet')->dispatch('open-task', id: $task->id)->assertNotFound();
    }

    public function test_an_existing_page_saves_itself_while_typing(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkauf', 'content' => ['body' => 'Milch'], 'created_by' => $user->id]);

        $this->actingAs($user);
        $component = Volt::test('pages.index', ['item' => $page->id])->call('edit', $page->id);
        $this->assertNotSame('', $component->instance()->autosave('Einkauf Samstag', 'Milch, Eier'));

        $page->refresh();
        $this->assertSame('Einkauf Samstag', $page->title);
        $this->assertSame('Milch, Eier', $page->body());

        // New pages (not saved yet) and empty titles are left alone.
        $this->assertSame('', Volt::test('pages.index')->instance()->autosave('Neu', 'Text'));
        $this->assertSame('', $component->instance()->autosave('   ', 'Leer'));
    }
}
