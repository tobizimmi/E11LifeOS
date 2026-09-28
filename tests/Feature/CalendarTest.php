<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\RealEstate;
use App\Models\RealEstateTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    private function task(Collection $tasks, string $title, string $due): Item
    {
        $task = Item::factory()->todo()->create(['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);
        $task->setPropertyValue($tasks->systemProperty('due'), $due);
        $task->save();

        return $task;
    }

    public function test_the_calendar_shows_dates_of_all_modules_and_sources_can_be_hidden(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id)->load('properties');
        $this->task($tasks, 'Zahnarzt anrufen', '2026-09-30');
        MealPlan::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'date' => '2026-10-01', 'meal_type' => 'dinner', 'recipe_id' => null, 'title' => 'Lasagne']);
        $flat = RealEstate::factory()->rental()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Lindenstraße']);
        RealEstateTenant::factory()->create(['real_estate_id' => $flat->id, 'name' => 'Erika Muster', 'due_day' => 3]);
        $this->actingAs($user);

        $this->get(route('calendar.index'))->assertOk()
            ->assertSee('Zahnarzt anrufen')
            ->assertSee('Lasagne')
            ->assertSee('Miete fällig: Erika Muster');

        Volt::test('calendar.index')->call('toggleSource', 'meals')
            ->assertSee('Zahnarzt anrufen')
            ->assertDontSee('Lasagne')
            ->call('toggleSource', 'unknown')->assertNotFound();
    }

    public function test_tasks_are_dragged_to_another_day_and_added_on_the_selected_day(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id)->load('properties');
        $task = $this->task($tasks, 'Müll rausbringen', '2026-09-30');
        $this->actingAs($user);

        Volt::test('calendar.index')
            ->call('moveTask', $task->id, '2026-10-05')
            ->call('selectDay', '2026-10-07')
            ->set('newTask', 'Reifen wechseln')
            ->call('addTask')
            ->assertHasNoErrors()
            ->assertSee('Reifen wechseln');

        $this->assertSame('2026-10-05', $task->fresh()->propertyValue($tasks->systemProperty('due')));
        $added = Item::where('title', 'Reifen wechseln')->sole();
        $this->assertSame('2026-10-07', $added->propertyValue($tasks->systemProperty('due')));
    }

    public function test_tasks_of_other_households_cannot_be_moved(): void
    {
        [$user] = $this->memberWithHousehold();
        [, $otherHousehold] = $this->memberWithHousehold();
        $foreign = $this->task(Collection::tasksFor($otherHousehold->id)->load('properties'), 'Fremd', '2026-09-30');
        $this->actingAs($user);

        Volt::test('calendar.index')->call('moveTask', $foreign->id, '2026-10-05')->assertNotFound();
        Volt::test('calendar.index')->call('moveTask', $foreign->id, 'kein-datum')->assertStatus(422);
    }
}
