<?php

namespace Tests\Feature;

use App\Events\HouseholdItemsChanged;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

use function Livewire\invade;

class HouseholdBroadcastingTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_creating_an_item_broadcasts_to_its_household_channel(): void
    {
        Event::fake([HouseholdItemsChanged::class]);

        [$user, $household] = $this->memberWithHousehold();

        $item = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Test',
            'created_by' => $user->id,
        ]);

        Event::assertDispatched(HouseholdItemsChanged::class, function (HouseholdItemsChanged $event) use ($household) {
            return $event->householdId === $household->id
                && $event->kind === Item::TYPE_NOTE
                && in_array("private-household.{$household->id}", array_map(
                    fn ($channel) => $channel->name,
                    $event->broadcastOn()
                ), true);
        });
    }

    public function test_changing_a_shopping_list_entry_broadcasts_to_its_household_channel(): void
    {
        Event::fake([HouseholdItemsChanged::class]);

        [$user, $household] = $this->memberWithHousehold();

        $entry = ShoppingListEntry::create([
            'household_id' => $household->id,
            'label' => 'Milch',
            'created_by' => $user->id,
        ]);

        $entry->update(['checked' => true]);

        Event::assertDispatched(HouseholdItemsChanged::class, function (HouseholdItemsChanged $event) use ($household) {
            return $event->householdId === $household->id && $event->kind === 'shopping';
        });
    }

    public function test_module_pages_rerender_on_household_broadcasts(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        foreach (['pages.index', 'todos.index', 'shopping-list.index', 'inbox.index', 'dashboard', 'layout.navigation'] as $component) {
            $listeners = invade(Volt::test($component)->instance())->getListeners();

            $this->assertSame('$refresh', $listeners["echo-private:household.{$household->id},.items.updated"] ?? null, $component);
        }
    }
}
