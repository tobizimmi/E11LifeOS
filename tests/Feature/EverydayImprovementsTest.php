<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\BrainSearch;
use App\Support\TaskLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class EverydayImprovementsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    public function test_the_dashboard_quick_capture_can_create_a_task_directly(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('quick-capture')->set('target', 'task')->set('title', 'Ölwechsel Freitag 17 Uhr !hoch')->call('capture')->assertHasNoErrors();
        Volt::test('quick-capture')->set('title', 'Idee für den Urlaub')->call('capture');

        $task = Item::ofType(Item::TYPE_TODO)->sole();
        $this->assertSame(['Ölwechsel', '2026-09-25', '17:00', 'high'], [$task->title, $task->dueDate(), $task->due_time, $task->propertyValue($tasks->systemProperty('priority'))]);
        $this->assertSame('Idee für den Urlaub', Item::ofType(Item::TYPE_INBOX)->sole()->title);
    }

    public function test_the_done_list_shows_a_weekly_review_grouped_by_completion_day(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $make = fn (string $title) => Item::factory()->todo()->create(['household_id' => $household->id, 'collection_id' => $tasks->id, 'title' => $title, 'created_by' => $user->id]);

        $this->travelTo(Carbon::parse('2026-09-17 09:00'));
        $make('Letzte Woche erledigt')->update(['done' => true]);
        $this->travelTo(Carbon::parse('2026-09-24 09:00'));
        $make('Gestern erledigt')->update(['done' => true]);
        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
        $make('Heute erledigt')->update(['done' => true]);
        $reopened = $make('Wieder offen');
        $reopened->update(['done' => true]);
        $reopened->update(['done' => false]);
        $this->assertNull($reopened->fresh()->completed_at);

        Volt::test('tasks.index')
            ->call('showList', TaskLists::DONE)
            ->assertViewHas('review', ['thisWeek' => 2, 'lastWeek' => 1, 'overdue' => 0])
            ->assertViewHas('groups', fn (array $groups) => array_column($groups, 'key') === ['done-today', 'done-yesterday', 'done-2026-09'])
            ->assertSee('Diese Woche erledigt');
    }

    public function test_searches_match_several_words_in_any_order_and_german_word_forms(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $page = Item::factory()->note()->for($household)->create(['title' => 'Plan für den Garten', 'content' => ['body' => 'Hochbeet bauen']]);
        Item::factory()->note()->for($household)->create(['title' => 'Gartenparty']);
        $insurance = Item::factory()->note()->for($household)->create(['title' => 'Versicherung Auto']);

        Volt::test('pages.index')->set('search', 'garten hochbeet')->assertSee('Plan für den Garten')->assertDontSee('Gartenparty');

        $keys = array_column(BrainSearch::documents('Welche Versicherungen haben wir?', $user, $household->id), 'key');
        $this->assertSame('item:'.$insurance->id, $keys[0]);
        $this->assertNotSame('item:'.$page->id, $keys[0]);
    }
}
