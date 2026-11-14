<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\FocusEntry;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FocusTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 09:00:00');
    }

    public function test_brain_dump_adds_an_entry_and_a_linked_todo(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('focus.index')
            ->set('alsoTask', true)
            ->set('brain', 'Angebot schreiben')
            ->call('addBrain')
            ->assertSet('brain', '');

        $entry = FocusEntry::sole();
        $this->assertSame('Angebot schreiben', $entry->title);
        $this->assertNull($entry->quadrant);
        $this->assertNotNull($entry->item_id); // also created as a to-do

        $task = Item::find($entry->item_id);
        $this->assertSame(Collection::tasksFor($household->id)->id, $task->collection_id);
    }

    public function test_brain_dump_without_the_todo_option_creates_no_task(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('focus.index')->set('alsoTask', false)->set('brain', 'Nur ein Gedanke')->call('addBrain');

        $this->assertNull(FocusEntry::sole()->item_id);
        $this->assertSame(0, Item::whereNotNull('collection_id')->where('title', 'Nur ein Gedanke')->count());
    }

    public function test_entries_are_sorted_into_quadrants_and_the_top_three_are_suggested(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $make = fn (string $title) => FocusEntry::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'date' => '2026-11-14', 'title' => $title]);
        $a = $make('A');
        $b = $make('B');
        $c = $make('C');
        $d = $make('D');

        $component = Volt::test('focus.index');
        $component->call('setQuadrant', $a->id, 'q1')
            ->call('setQuadrant', $b->id, 'q1')
            ->call('setQuadrant', $c->id, 'q2')
            ->call('setQuadrant', $d->id, 'q3');

        $component->call('suggestMits');

        // Top tasks come from q1 then q2, capped at three; q3 is never a MIT.
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], FocusEntry::where('is_mit', true)->pluck('id')->all());
        $this->assertFalse($d->fresh()->is_mit);
    }

    public function test_at_most_three_most_important_tasks(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $ids = collect(range(1, 4))->map(fn ($i) => FocusEntry::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'date' => '2026-11-14', 'quadrant' => 'q1', 'title' => "T$i"])->id);

        $component = Volt::test('focus.index');
        foreach ($ids as $id) {
            $component->call('toggleMit', $id);
        }

        $this->assertSame(3, FocusEntry::where('is_mit', true)->count());
    }

    public function test_a_task_is_time_boxed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $entry = FocusEntry::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'date' => '2026-11-14', 'quadrant' => 'q1', 'is_mit' => true]);

        Volt::test('focus.index')
            ->call('openSchedule', $entry->id)
            ->set('atTime', '08:30')
            ->set('forMinutes', 90)
            ->call('saveSchedule');

        $entry->refresh();
        $this->assertSame(510, $entry->start_minute); // 8*60 + 30
        $this->assertSame(90, $entry->duration_minutes);
        $this->assertSame('08:30–10:00', $entry->timeRange());
    }

    public function test_completing_a_focus_entry_completes_the_linked_todo(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $task = Item::create(['household_id' => $household->id, 'created_by' => $user->id, 'collection_id' => Collection::tasksFor($household->id)->id, 'title' => 'Task', 'done' => false]);
        $entry = FocusEntry::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'date' => '2026-11-14', 'item_id' => $task->id]);

        Volt::test('focus.index')->call('toggleDone', $entry->id);

        $this->assertTrue($entry->fresh()->done);
        $this->assertTrue($task->fresh()->done);
    }

    public function test_open_todos_can_be_pulled_in(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $collectionId = Collection::tasksFor($household->id)->id;
        Item::create(['household_id' => $household->id, 'created_by' => $user->id, 'collection_id' => $collectionId, 'title' => 'Offen', 'done' => false]);
        Item::create(['household_id' => $household->id, 'created_by' => $user->id, 'collection_id' => $collectionId, 'title' => 'Erledigt', 'done' => true]);

        Volt::test('focus.index')->call('importTasks');

        $titles = FocusEntry::pluck('title')->all();
        $this->assertContains('Offen', $titles);
        $this->assertNotContains('Erledigt', $titles); // done tasks are not pulled in
    }

    public function test_focus_entries_are_personal_to_each_member(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $other = $this->addMemberTo($household);

        FocusEntry::factory()->create(['household_id' => $household->id, 'user_id' => $owner->id, 'date' => '2026-11-14', 'title' => 'Owner only']);

        $this->actingAs($owner);
        Volt::test('focus.index')->assertSee('Owner only');

        $this->actingAs($other);
        Volt::test('focus.index')->assertDontSee('Owner only');
    }
}
