<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\Item;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripPackingListsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_each_member_keeps_a_personal_list_only_they_see(): void
    {
        [$anna, $household] = $this->memberWithHousehold();
        $ben = $this->addMemberTo($household);
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $anna->id]);

        $this->actingAs($anna);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'packing')
            ->set('item.label', 'Zelt')->call('addItem')
            ->set('packingList', 'mine')->set('template', 'beach')->call('applyTemplate')
            ->set('item.label', 'Tagebuch')->call('addItem')
            ->assertSee('Tagebuch')->assertDontSee('Zelt');

        $diary = $trip->packingItems()->where('label', 'Tagebuch')->sole();
        $this->assertSame($anna->id, $diary->owner_id);
        $this->assertNull($trip->packingItems()->where('label', 'Zelt')->sole()->owner_id);
        $this->assertTrue($trip->packingItems()->where('owner_id', $anna->id)->where('category', 'clothes')->exists());

        $this->actingAs($ben);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'packing')->assertSee('Zelt')->assertDontSee('Tagebuch')
            ->set('packingList', 'mine')->assertDontSee('Tagebuch')
            ->call('togglePacked', $diary->id)->assertNotFound();
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'packing')->call('deleteItem', $diary->id)->assertNotFound();
        $this->assertFalse($diary->fresh()->packed);

        $this->get(route('trips.itinerary', $trip))->assertOk()->assertSee('Zelt')->assertDontSee('Tagebuch');
    }

    public function test_an_own_template_works_for_trips_tasks_and_pages(): void
    {
        [$anna, $household] = $this->memberWithHousehold();
        $ben = $this->addMemberTo($household);
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $anna->id]);
        $other = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $anna->id]);

        $this->actingAs($anna);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'packing')
            ->set('item.label', 'Stirnlampe')->set('item.category', 'electronics')->call('addItem')
            ->set('item.label', 'Isomatte')->set('item.category', 'other')->call('addItem')
            ->set('templateName', 'Zeltwochenende')->set('templatePrivate', true)->call('saveTemplate')->assertHasNoErrors();

        $template = ChecklistTemplate::sole();
        $this->assertTrue($template->isPrivate());
        $this->assertSame([['Stirnlampe', 'electronics'], ['Isomatte', 'other']], $template->packing);

        Volt::test('trips.show', ['trip' => $other->id])->set('tab', 'packing')->assertSee('Zeltwochenende')
            ->set('template', 'own-'.$template->id)->call('applyTemplate');
        $this->assertSame('electronics', $other->packingItems()->where('label', 'Stirnlampe')->sole()->category);

        Volt::test('tasks.index')->call('openTemplates')->assertSee('Zeltwochenende')
            ->call('chooseTemplate', 'own-'.$template->id)->call('applyTemplate');
        $task = Item::where('type', Item::TYPE_TODO)->where('title', 'Zeltwochenende')->sole();
        $this->assertSame(['Stirnlampe', 'Isomatte'], array_column($task->subtasks(), 'text'));

        Volt::test('pages.index')->call('createFromTemplate', 'checklist:'.$template->id)->assertSet('body', "- [ ] Stirnlampe\n- [ ] Isomatte");

        // Private: the other member neither sees nor uses it.
        $this->actingAs($ben);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'packing')->assertDontSee('Zeltwochenende')
            ->set('template', 'own-'.$template->id)->call('applyTemplate')->assertNotFound();
        Volt::test('pages.index')->call('createFromTemplate', 'checklist:'.$template->id)->assertNotFound();
    }
}
