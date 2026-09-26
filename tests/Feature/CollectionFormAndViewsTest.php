<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\PropertyDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class CollectionFormAndViewsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function contracts(): Collection
    {
        Volt::test('collections.index')->set('name', 'Verträge')->set('template', 'contracts')->call('create');

        return Collection::where('name', 'Verträge')->sole();
    }

    public function test_new_entries_are_created_with_the_form_popup(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $property = fn (string $name) => $contracts->properties->firstWhere('name', $name);

        $component = Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('newEntry')
            ->assertSet('showEntryForm', true)
            ->assertSee('Neuer Eintrag')
            ->call('saveEntryForm')
            ->assertHasErrors('form.title')
            ->set('form.title', 'Handyvertrag')
            ->set('form.values.'.$property('Kategorie')->id, $property('Kategorie')->selectOptions()[2]['id'])
            ->set('form.values.'.$property('Monatliche Kosten (€)')->id, '12.99')
            ->set('form.values.'.$property('Kündigen bis')->id, '2026-10-24')
            ->set('form.values.'.$property('Website')->id, 'kein link')
            ->set('form.tags', 'handy, vertrag')
            ->set('form.body', 'PIN im Safe')
            ->call('saveEntryForm', true)
            ->assertHasNoErrors()
            ->assertSet('showEntryForm', true)
            ->assertSet('form.title', '');

        $entry = Item::where('title', 'Handyvertrag')->sole();
        $this->assertSame(12.99, $entry->propertyValue($property('Monatliche Kosten (€)')));
        $this->assertSame('2026-10-24', $entry->propertyValue($property('Kündigen bis')));
        $this->assertNull($entry->propertyValue($property('Website')));
        $this->assertSame(['handy', 'vertrag'], $entry->tags->pluck('name')->sort()->values()->all());
        $this->assertSame('PIN im Safe', $entry->body());

        $component->call('closeEntryForm')->assertSet('showEntryForm', false);
    }

    public function test_entries_are_edited_in_the_form_and_new_options_can_be_added_there(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $category = $contracts->properties->firstWhere('name', 'Kategorie');
        $entry = Item::create(['household_id' => $household->id, 'collection_id' => $contracts->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Strom']);

        Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('openEntry', $entry->id)
            ->assertSee('Bearbeiten')
            ->call('editEntry', $entry->id)
            ->assertSet('form.title', 'Strom')
            ->set('form.title', 'Stromvertrag')
            ->call('addFormOption', $category->id, 'Haushalt')
            ->call('saveEntryForm')
            ->assertSet('showEntryForm', false);

        $option = collect($category->fresh()->selectOptions())->firstWhere('name', 'Haushalt');
        $this->assertNotNull($option);
        $this->assertSame(['Stromvertrag', $option['id']], [$entry->fresh()->title, $entry->fresh()->propertyValue($category)]);
    }

    public function test_the_form_is_prefilled_from_board_columns_and_calendar_days(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('newEntry', 'doing', '2026-10-01')
            ->assertSet('form.values.'.$tasks->systemProperty('status')->id, 'doing')
            ->assertSet('form.values.'.$tasks->systemProperty('due')->id, '2026-10-01')
            ->set('form.title', 'Aus dem Kalender')
            ->call('saveEntryForm');

        $task = Item::where('title', 'Aus dem Kalender')->sole();
        $this->assertSame([Item::TYPE_TODO, '2026-10-01'], [$task->type, $task->dueDate()]);
    }

    public function test_contracts_open_as_cards_with_formatted_values_sums_and_selectable_fields(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $cost = $contracts->properties->firstWhere('name', 'Monatliche Kosten (€)');
        $cancelBy = $contracts->properties->firstWhere('name', 'Kündigen bis');
        foreach ([['Handy', 12.99, '2026-10-15'], ['Internet', 39.9, '2027-03-01']] as [$title, $amount, $date]) {
            $entry = Item::create(['household_id' => $household->id, 'collection_id' => $contracts->id, 'type' => Item::TYPE_ENTRY, 'title' => $title]);
            $entry->setPropertyValue($cost, $amount);
            $entry->setPropertyValue($cancelBy, $date);
            $entry->save();
        }

        $this->assertSame('cards', $contracts->default_view);

        Volt::test('collections.show', ['collection' => $contracts->id])
            ->assertSet('viewMode', '')
            ->assertViewHas('currentView', 'cards')
            ->assertSee('12,99 €')
            ->assertSee('in 20 Tagen')
            ->assertSee('52,89 €')
            ->call('toggleHiddenProperty', $cost->id)
            ->assertDontSee('12,99 €')
            ->call('saveView', 'Ohne Kosten')
            ->set('viewMode', 'list')
            ->assertSee('15. Okt 2026');

        $this->assertSame([$cost->id], $contracts->views()->sole()->settings['hiddenProperties']);
    }

    public function test_deadline_dates_are_highlighted_by_how_close_they_are(): void
    {
        [, $household] = $this->memberWithHousehold();
        $contracts = Collection::factory()->for($household)->create();
        $deadline = $contracts->properties()->create(['name' => 'Kündigen bis', 'type' => 'date', 'options' => ['remind_days' => 30], 'position' => 0]);
        $plain = $contracts->properties()->create(['name' => 'Start', 'type' => 'date', 'position' => 1]);
        $entry = Item::create(['household_id' => $household->id, 'collection_id' => $contracts->id, 'type' => Item::TYPE_ENTRY, 'title' => 'X']);

        $tone = function ($property, string $date) use ($entry) {
            $entry->setPropertyValue($property, $date);

            return PropertyDisplay::format($property, $entry)['tone'];
        };

        $this->assertSame('danger', $tone($deadline, '2026-09-20'));
        $this->assertSame('warning', $tone($deadline, '2026-10-10'));
        $this->assertSame('normal', $tone($deadline, '2027-01-10'));
        $this->assertSame('normal', $tone($plain, '2026-09-20'));
    }

    public function test_a_task_can_be_added_and_opened_for_details_in_one_step(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('tasks.index')->call('addTaskWithDetails', 'Urlaub planen')
            ->assertSet('openTaskId', (string) Item::where('title', 'Urlaub planen')->sole()->id)
            ->assertSee('Unteraufgaben');
    }
}
