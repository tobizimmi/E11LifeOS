<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\DashboardWidgets;
use App\Support\TaskLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DragAndDropTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    private function task(Collection $tasks, string $title, ?string $due = null): Item
    {
        $task = Item::factory()->todo()->create(['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);

        if ($due) {
            $task->setPropertyValue($tasks->systemProperty('due'), $due);
            $task->save();
        }

        return $task;
    }

    public function test_dashboard_cards_are_reordered_by_dragging(): void
    {
        [$user] = $this->memberWithHousehold();
        $user->forceFill(['dashboard_widgets' => ['tasks', 'shopping', 'pages', 'portfolio']])->save();
        $user->currentHousehold()->setAddon('portfolio', false);
        $this->actingAs($user);

        Volt::test('dashboard')->call('sortWidget', 'pages', 0);
        // The hidden portfolio card keeps its place at the end.
        $this->assertSame(['pages', 'tasks', 'shopping', 'portfolio'], DashboardWidgets::forUser($user->fresh()));

        Volt::test('dashboard')->call('sortWidget', 'portfolio', 0)->assertNotFound();
    }

    public function test_tasks_are_dragged_to_other_days_and_onto_the_lists(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id)->load('properties');
        $milk = $this->task($tasks, 'Milch holen', '2026-09-28');
        $call = $this->task($tasks, 'Oma anrufen', '2026-09-28');
        $tax = $this->task($tasks, 'Steuer');
        $this->actingAs($user);
        $due = fn (Item $task) => (string) $task->fresh()->propertyValue($tasks->systemProperty('due'));

        Volt::test('tasks.index')->set('list', TaskLists::ALL)
            ->call('moveTask', $call->id, 'today', 0)
            ->call('moveTask', $tax->id, 'tomorrow', 0)
            ->call('moveTask', $milk->id, 'none', 0)
            ->call('moveTask', $milk->id, 'day-nope', 0)->assertNotFound();

        $this->assertSame(['2026-09-28', '2026-09-29', ''], [$due($call), $due($tax), $due($milk)]);
        $this->assertSame(1, $call->fresh()->position);

        Volt::test('tasks.index')->set('list', TaskLists::UPCOMING)->call('moveTask', $tax->id, 'day-2026-10-02', 0);
        $this->assertSame('2026-10-02', $due($tax));

        Volt::test('tasks.index')
            ->call('dropOnList', $milk->id, TaskLists::TODAY)
            ->call('dropOnList', $tax->id, TaskLists::MINE)
            ->call('dropOnList', $call->id, TaskLists::DONE);

        $this->assertSame('2026-09-28', $due($milk));
        $this->assertSame((string) $user->id, (string) $tax->fresh()->propertyValue($tasks->systemProperty('assignee')));
        $this->assertTrue($call->fresh()->done);
    }

    public function test_file_drop_areas_are_offered_where_files_are_attached(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $entry = $this->task(Collection::tasksFor($household->id), 'Versicherung');
        $this->actingAs($user);

        Volt::test('documents-panel', ['type' => 'item', 'id' => $entry->id])->assertSee('Dateien hier ablegen');
    }
}
