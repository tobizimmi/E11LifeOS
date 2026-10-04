<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\MealPlan;
use App\Support\TodayView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TodayViewTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function task(Collection $tasks, string $title, string $due, ?string $time = null): Item
    {
        $task = Item::create(['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'type' => Item::TYPE_TODO, 'title' => $title, 'due_time' => $time]);
        $task->setPropertyValue($tasks->systemProperty('due'), $due);
        $task->save();

        return $task;
    }

    public function test_my_day_shows_the_timeline_what_comes_next_and_tomorrow_in_the_evening(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 08:30'));
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $call = $this->task($tasks, 'Zahnarzt anrufen', '2026-10-05', '09:00');
        $this->task($tasks, 'Steuer abgeben', '2026-10-05');
        $this->task($tasks, 'Altpapier rausstellen', '2026-10-06', '07:00');
        $this->task($tasks, 'Rechnung zahlen', '2026-10-01');
        MealPlan::create(['household_id' => $household->id, 'created_by' => $user->id, 'date' => '2026-10-05', 'meal_type' => 'dinner', 'title' => 'Lasagne']);

        $morning = TodayView::for($user, $household->id);
        $this->assertSame(TodayView::MORNING, $morning['mode']);
        $this->assertSame('Zahnarzt anrufen', $morning['next']['title']);
        $this->assertSame(30, $morning['minutesUntilNext']);
        $this->assertSame(1, $morning['overdue']);
        $this->assertSame([], $morning['tomorrow']);
        $this->assertEqualsCanonicalizing(['Zahnarzt anrufen', 'Steuer abgeben', '🍲 Lasagne'], array_column($morning['events'], 'title'));

        $this->actingAs($user);
        Volt::test('dashboard')->call('loadWidgets')
            ->assertSee(__('Your morning'))->assertSee('Zahnarzt anrufen')->assertSee(__('In :minutes min', ['minutes' => 30]))
            ->call('toggleTodo', $call->id);
        $this->assertTrue($call->fresh()->done);

        $this->travelTo(Carbon::parse('2026-10-05 20:15'));
        $evening = TodayView::for($user, $household->id);
        $this->assertSame(TodayView::EVENING, $evening['mode']);
        $this->assertNull($evening['next']);
        $this->assertSame(1, $evening['done']);
        $this->assertSame(['Altpapier rausstellen'], array_column($evening['tomorrow'], 'title'));
        Volt::test('dashboard')->call('loadWidgets')->assertSee(__('Your evening'))->assertSee('Altpapier rausstellen');
    }
}
