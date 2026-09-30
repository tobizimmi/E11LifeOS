<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\Collection;
use App\Models\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ChecklistTemplatesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_a_built_in_checklist_creates_tasks_with_items_relative_to_the_chosen_day(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $due = Collection::tasksFor($household->id)->load('properties')->systemProperty('due');

        Volt::test('tasks.index')
            ->call('openTemplates')
            ->assertSee('Umzug')->assertSee('Steuererklärung')
            ->call('chooseTemplate', 'moving')
            ->assertSet('templateDate', '2026-11-27')
            ->set('templateDate', '2026-12-01')
            ->assertSee('Beim Bürgeramt ummelden')
            ->call('applyTemplate')
            ->assertHasNoErrors()
            ->assertSet('templatesOpen', false);

        $tasks = Item::where('household_id', $household->id)->orderBy('position')->get();
        $this->assertCount(5, $tasks);
        $this->assertSame('Umzug: zwei Monate vorher', $tasks[0]->title);
        $this->assertSame('2026-10-02', $tasks[0]->propertyValue($due));
        $this->assertSame('2026-12-01', $tasks[3]->propertyValue($due));
        $this->assertContains('Alte Wohnung kündigen', array_column($tasks[0]->subtasks(), 'text'));
    }

    public function test_a_task_is_saved_as_own_template_and_reused(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => 'Wocheneinkauf planen']);
        $task->setSubtasks([['id' => 'a', 'text' => 'Kühlschrank checken', 'done' => true], ['id' => 'b', 'text' => 'Angebote ansehen', 'done' => false]]);
        $task->save();

        Volt::test('tasks.index')->call('saveAsTemplate', $task->id);
        $template = ChecklistTemplate::sole();
        $this->assertSame(['Kühlschrank checken', 'Angebote ansehen'], $template->tasks[0]['subtasks']);

        Volt::test('tasks.index')
            ->call('openTemplates')
            ->call('chooseTemplate', 'own-'.$template->id)
            ->call('applyTemplate');

        $copy = Item::where('title', 'Wocheneinkauf planen')->latest('id')->first();
        $this->assertNotSame($task->id, $copy->id);
        $this->assertSame([false, false], array_column($copy->subtasks(), 'done'));

        Volt::test('tasks.index')->call('openTemplates')->call('deleteTemplate', $template->id);
        $this->assertModelMissing($template);
    }

    public function test_templates_of_other_households_cannot_be_used(): void
    {
        [$user] = $this->memberWithHousehold();
        [, $otherHousehold] = $this->memberWithHousehold();
        $foreign = ChecklistTemplate::create(['household_id' => $otherHousehold->id, 'name' => 'Fremd', 'tasks' => [['title' => 'X', 'offset' => 0, 'subtasks' => []]]]);
        $this->actingAs($user);

        Volt::test('tasks.index')->call('openTemplates')->call('chooseTemplate', 'own-'.$foreign->id)->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        Volt::test('tasks.index')->call('deleteTemplate', $foreign->id);
    }

    public function test_the_inventory_template_reminds_of_warranty_and_maintenance(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('collections.index')->set('name', 'Inventar')->set('template', 'inventory')->call('create');

        $collection = Collection::where('name', 'Inventar')->sole();
        $properties = $collection->properties->keyBy('name');
        $this->assertSame(30, $properties['Garantie bis']->remindDays());
        $this->assertSame(7, $properties['Nächste Wartung']->remindDays());
        $this->assertSame('cards', $collection->default_view);
    }
}
