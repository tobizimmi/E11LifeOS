<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionView;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SavedViewsAndFiltersTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function task(Collection $tasks, string $title, array $values = []): Item
    {
        $task = Item::factory()->todo()->create(['household_id' => $tasks->household_id, 'title' => $title]);

        foreach ($values as $key => $value) {
            $task->setPropertyValue($tasks->systemProperty($key), $value);
        }

        $task->save();

        return $task;
    }

    public function test_tasks_can_be_filtered_by_property_conditions(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $tasks = Collection::tasksFor($household->id);
        $due = $tasks->systemProperty('due');
        $assignee = $tasks->systemProperty('assignee');
        $this->task($tasks, 'Überfällig für mich', ['due' => '2026-09-20', 'assignee' => $user->id]);
        $this->task($tasks, 'Heute für Partner', ['due' => '2026-09-25', 'assignee' => $partner->id]);
        $this->task($tasks, 'Nächste Woche für mich', ['due' => '2026-09-30', 'assignee' => $user->id]);
        $this->task($tasks, 'Ohne Datum');
        $this->actingAs($user);

        $component = Volt::test('collections.show', ['collection' => $tasks->id, 'viewMode' => 'table'])
            ->call('addFilter')
            ->call('setFilterProperty', 0, (string) $assignee->id)
            ->assertSet('filters.0.operator', 'me')
            ->assertSee('Überfällig für mich')->assertSee('Nächste Woche für mich')
            ->assertDontSee('Heute für Partner')->assertDontSee('Ohne Datum');

        $component->call('addFilter')
            ->call('setFilterProperty', 1, (string) $due->id)
            ->set('filters.1.operator', 'overdue')
            ->assertSee('Überfällig für mich')->assertDontSee('Nächste Woche für mich');

        $component->set('filters.1.operator', 'next_7_days')->assertSee('Nächste Woche für mich')->assertDontSee('Überfällig für mich');

        $component->call('removeFilter', 1)->call('removeFilter', 0)->assertSee('Ohne Datum');

        // Incomplete filters (no value yet) filter nothing; unknown properties are ignored.
        $component->set('filters', [['property' => 'title', 'operator' => 'contains', 'value' => ''], ['property' => '999', 'operator' => 'is', 'value' => 'x']])
            ->assertSee('Ohne Datum')->assertSee('Heute für Partner');
        $component->set('filters', [['property' => 'title', 'operator' => 'contains', 'value' => 'partner']])
            ->assertSee('Heute für Partner')->assertDontSee('Ohne Datum');
    }

    public function test_views_are_saved_applied_updated_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $tasks = Collection::tasksFor($household->id);
        $this->task($tasks, 'Rasen mähen', ['due' => '2026-09-26']);
        $this->task($tasks, 'Steuer', ['due' => '2026-12-01']);
        $this->actingAs($user);

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->set('viewMode', 'table')
            ->set('hideDone', true)
            ->set('filters', [['property' => (string) $tasks->systemProperty('due')->id, 'operator' => 'next_7_days', 'value' => '']])
            ->call('saveView', 'Diese Woche')
            ->assertSee('Diese Woche');

        $view = CollectionView::sole();
        $this->assertSame(['table', true], [$view->settings['viewMode'], $view->settings['hideDone']]);

        // Everyone in the household sees and can apply the view.
        $this->actingAs($partner);
        $component = Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('applyView', $view->id)
            ->assertSet('viewMode', 'table')
            ->assertSet('activeView', (string) $view->id)
            ->assertSee('Rasen mähen')
            ->assertDontSee('Steuer');

        $component->set('search', 'rasen')->call('updateView');
        $this->assertSame('rasen', $view->fresh()->settings['search']);

        $component->call('clearView')->assertSet('filters', [])->assertSet('activeView', '')->assertSee('Steuer');
        $component->call('deleteView', $view->id);
        $this->assertSame(0, CollectionView::count());
    }

    public function test_multi_select_and_number_filters(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Budget')->set('template', 'budget')->call('create');
        $budget = Collection::where('name', 'Budget')->sole();
        $amount = $budget->properties->firstWhere('name', 'Betrag (€)');
        foreach (['Kleinkram' => 12, 'Großeinkauf' => 180] as $title => $value) {
            $entry = Item::create(['household_id' => $budget->household_id, 'collection_id' => $budget->id, 'type' => Item::TYPE_ENTRY, 'title' => $title]);
            $entry->setPropertyValue($amount, $value);
            $entry->save();
        }

        Volt::test('collections.show', ['collection' => $budget->id])
            ->set('filters', [['property' => (string) $amount->id, 'operator' => 'gt', 'value' => '50']])
            ->assertSee('Großeinkauf')->assertDontSee('Kleinkram')
            ->assertSee('Σ 180');
    }
}
