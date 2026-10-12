<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\Recurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecurringTasksAndCalendarTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    public function test_the_next_due_date_follows_the_rule_and_lies_in_the_future(): void
    {
        $today = Carbon::parse('2026-09-25');

        $this->assertSame('2026-09-29', Recurrence::next('2026-09-22', Recurrence::WEEKLY, $today));
        $this->assertSame('2026-09-26', Recurrence::next('2026-09-20', Recurrence::DAILY, $today));
        $this->assertSame('2026-10-08', Recurrence::next('2026-10-01', Recurrence::WEEKLY, $today));
        $this->assertSame('2026-09-30', Recurrence::next('2026-01-31', Recurrence::MONTHLY, $today));
        // The original day survives short months: 31 Aug → (30 Sep) → 31 Oct.
        $this->assertSame('2026-10-31', Recurrence::next('2026-08-31', Recurrence::MONTHLY, Carbon::parse('2026-10-15')));
        $this->assertSame('2027-02-28', Recurrence::next('2024-02-29', Recurrence::YEARLY, $today));
        $this->assertNull(Recurrence::next('2026-09-25', 'hourly', $today));
    }

    public function test_completing_a_repeating_task_moves_it_to_the_next_due_date(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');
        $repeat = $tasks->systemProperty('repeat');

        $todo = Item::factory()->todo()->for($household)->create(['title' => 'Müll rausbringen']);
        $todo->setPropertyValue($due, '2026-09-22');
        $todo->setPropertyValue($repeat, Recurrence::WEEKLY);
        $todo->save();

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('toggleDone', $todo->id)
            ->assertDispatched('toast');

        $todo->refresh();
        $this->assertFalse($todo->done);
        $this->assertSame('2026-09-29', $todo->propertyValue($due));
        $this->assertSame(Collection::TASK_STATUS_TODO, $todo->propertyValue($tasks->systemProperty('status')));
    }

    public function test_repeating_tasks_rotate_between_members(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');
        $assignee = $tasks->systemProperty('assignee');

        $todo = Item::factory()->todo()->for($household)->create(['title' => 'Bad putzen', 'created_by' => $user->id]);
        $todo->setPropertyValue($due, '2026-09-25');
        $todo->setPropertyValue($tasks->systemProperty('repeat'), Recurrence::WEEKLY);
        $todo->save();

        // Rotation partner → me: the first person gets the task right away.
        Volt::test('tasks.index')->call('toggleRotation', $todo->id, $partner->id)->call('toggleRotation', $todo->id, $user->id)
            ->set('openTaskId', (string) $todo->id)->assertSee('Abwechselnd')->assertSee('1.');
        $todo->refresh();
        $this->assertSame([$partner->id, $user->id], $todo->rotation());
        $this->assertSame($partner->id, (int) $todo->propertyValue($assignee));

        // Every completion hands it to the next one.
        $todo->update(['done' => true]);
        $this->assertSame($user->id, (int) $todo->fresh()->propertyValue($assignee));
        $this->assertSame('2026-10-02', $todo->fresh()->propertyValue($due));
        $todo->fresh()->update(['done' => true]);
        $this->assertSame($partner->id, (int) $todo->fresh()->propertyValue($assignee));

        // Removing a person ends the rotation; strangers cannot be added.
        Volt::test('tasks.index')->call('toggleRotation', $todo->id, $user->id)->call('toggleRotation', $todo->id, 99999)->assertNotFound();
        $this->assertSame([$partner->id], $todo->fresh()->rotation());
        $todo->fresh()->update(['done' => true]);
        $this->assertSame($partner->id, (int) $todo->fresh()->propertyValue($assignee));
    }

    public function test_setting_the_status_to_done_also_reschedules_and_tasks_without_due_date_just_close(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $status = $tasks->systemProperty('status');
        $repeat = $tasks->systemProperty('repeat');

        $withDate = Item::factory()->todo()->for($household)->create();
        $withDate->setPropertyValue($tasks->systemProperty('due'), '2026-09-01');
        $withDate->setPropertyValue($repeat, Recurrence::MONTHLY);
        $withDate->save();

        $withoutDate = Item::factory()->todo()->for($household)->create();
        $withoutDate->setPropertyValue($repeat, Recurrence::DAILY);
        $withoutDate->save();

        $component = Volt::test('collections.show', ['collection' => $tasks->id]);
        $component->call('updateProperty', $withDate->id, $status->id, Collection::TASK_STATUS_DONE);
        $component->call('updateProperty', $withoutDate->id, $status->id, Collection::TASK_STATUS_DONE);

        $this->assertFalse($withDate->fresh()->done);
        $this->assertSame('2026-10-01', $withDate->fresh()->propertyValue($tasks->systemProperty('due')));
        $this->assertTrue($withoutDate->fresh()->done);
    }

    public function test_new_households_get_the_repeat_property(): void
    {
        [, $household] = $this->memberWithHousehold();

        $repeat = Collection::tasksFor($household->id)->systemProperty('repeat');

        $this->assertSame(CollectionProperty::TYPE_SELECT, $repeat->type);
        $this->assertSame([Recurrence::DAILY, Recurrence::WEEKLY, Recurrence::MONTHLY, Recurrence::YEARLY], array_column($repeat->selectOptions(), 'id'));
    }

    public function test_the_calendar_places_entries_on_their_dates(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');

        $dated = Item::factory()->todo()->for($household)->create(['title' => 'Zahnarzt']);
        $dated->setPropertyValue($due, '2026-09-14');
        $dated->save();
        Item::factory()->todo()->for($household)->create(['title' => 'Irgendwann']);

        $component = Volt::test('collections.show', ['collection' => $tasks->id])
            ->set('viewMode', 'calendar')
            ->assertViewHas('currentView', 'calendar')
            ->assertSee('September 2026');

        $calendar = $component->viewData('calendar');
        $days = collect($calendar['weeks'])->flatten(1);

        $this->assertSame('2026-08-31', $days->first()['date']);
        $this->assertSame('2026-10-04', $days->last()['date']);
        $this->assertSame(['Zahnarzt'], collect($days->firstWhere('date', '2026-09-14')['entries'])->pluck('title')->all());
        $this->assertSame(['Irgendwann'], collect($calendar['undated'])->pluck('title')->all());

        $component->call('changeMonth', 1)->assertSee('Oktober 2026')->assertSet('month', '2026-10');
    }

    public function test_entries_can_be_moved_between_days_and_created_on_a_day(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::factory()->for($household)->create();
        $date = $collection->properties()->create(['name' => 'Termin', 'type' => CollectionProperty::TYPE_DATE]);
        $entry = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $collection->id]);

        $component = Volt::test('collections.show', ['collection' => $collection->id])->set('viewMode', 'calendar');

        $component->call('moveToDate', $entry->id, '2026-09-30');
        $this->assertSame('2026-09-30', $entry->fresh()->propertyValue($date));

        $component->call('moveToDate', $entry->id, '');
        $this->assertNull($entry->fresh()->propertyValue($date));

        $component->call('addEntryOnDate', '2026-09-18', 'Elternabend');
        $this->assertSame('2026-09-18', Item::where('title', 'Elternabend')->firstOrFail()->propertyValue($date));

        $component->call('addEntryOnDate', 'kein-datum', 'Ungültig');
        $this->assertDatabaseMissing('items', ['title' => 'Ungültig']);
    }

    public function test_collections_without_a_date_property_fall_back_to_the_table(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = Collection::factory()->for($household)->create();

        Volt::test('collections.show', ['collection' => $collection->id])
            ->set('viewMode', 'calendar')
            ->assertViewHas('currentView', 'table');
    }

    /**
     * Regression test for the anchor day drifting down a short month at a time
     * (31 Jan → 28 Feb → 28 Mar instead of 31 Mar) when a monthly task is ticked off
     * on time every single month, several months in a row.
     */
    public function test_a_monthly_task_ticked_off_on_time_each_month_keeps_the_31st(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');
        $repeat = $tasks->systemProperty('repeat');

        $this->travelTo(Carbon::parse('2026-01-31 10:00'));

        $todo = Item::factory()->todo()->for($household)->create(['title' => 'Miete überweisen']);
        $todo->setPropertyValue($due, '2026-01-31');
        $todo->setPropertyValue($repeat, Recurrence::MONTHLY);
        $todo->save();

        $todo->fresh()->update(['done' => true]);
        $this->assertSame('2026-02-28', $todo->fresh()->propertyValue($due));

        $this->travelTo(Carbon::parse('2026-02-28 10:00'));
        $todo->fresh()->update(['done' => true]);
        // Without the fix the anchor would be re-derived from 28 Feb, landing on 28 Mar
        // instead of the original 31st.
        $this->assertSame('2026-03-31', $todo->fresh()->propertyValue($due));

        $this->travelTo(Carbon::parse('2026-03-31 10:00'));
        $todo->fresh()->update(['done' => true]);
        $this->assertSame('2026-04-30', $todo->fresh()->propertyValue($due));
    }

    public function test_dragging_a_monthly_task_to_a_new_date_moves_its_anchor_too(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');
        $repeat = $tasks->systemProperty('repeat');

        $this->travelTo(Carbon::parse('2026-01-31 10:00'));

        $todo = Item::factory()->todo()->for($household)->create(['title' => 'Müll rausbringen']);
        $todo->setPropertyValue($due, '2026-01-31');
        $todo->setPropertyValue($repeat, Recurrence::MONTHLY);
        $todo->save();

        // First completion fixes the anchor at 31, as above.
        $todo->fresh()->update(['done' => true]);
        $this->assertSame('2026-02-28', $todo->fresh()->propertyValue($due));

        // The member now drags the task to the 15th by hand — not by ticking it off.
        $todo = $todo->fresh();
        $todo->setPropertyValue($due, '2026-05-15');
        $todo->save();
        $this->assertSame(15, $todo->fresh()->repeatAnchorDay());

        // Completing it afterwards must stay near the 15th, not snap back to month-end.
        $this->travelTo(Carbon::parse('2026-05-15 10:00'));
        $todo->fresh()->update(['done' => true]);
        $this->assertSame('2026-06-15', $todo->fresh()->propertyValue($due));
    }

    /**
     * occurrencesBetween() must fast-forward arithmetically instead of stepping through
     * every single day since the due date – otherwise an old daily repeat needs
     * thousands of next() calls just to reach the requested window.
     */
    public function test_occurrences_between_stays_fast_for_an_old_daily_repeat(): void
    {
        $due = today()->subYears(3)->format('Y-m-d');
        $start = today();
        $end = today()->copy()->addDays(5);

        $startedAt = microtime(true);
        $dates = Recurrence::occurrencesBetween($due, Recurrence::DAILY, $start, $end);
        $elapsed = microtime(true) - $startedAt;

        $this->assertSame(
            collect(range(0, 5))->map(fn (int $offset) => $start->copy()->addDays($offset)->format('Y-m-d'))->all(),
            $dates
        );
        $this->assertLessThan(0.5, $elapsed, 'occurrencesBetween should fast-forward instead of stepping through every day since the due date.');
    }

    /**
     * The fast-forward jump for an old monthly repeat must keep landing on the anchor
     * day (or the month's last day), the same as stepping through it one month at a time.
     */
    public function test_occurrences_between_keeps_the_anchor_day_for_an_old_monthly_repeat(): void
    {
        $dates = Recurrence::occurrencesBetween('2023-01-31', Recurrence::MONTHLY, Carbon::parse('2026-09-01'), Carbon::parse('2026-11-30'));

        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], $dates);
    }
}
