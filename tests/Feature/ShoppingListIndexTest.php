<?php

namespace Tests\Feature;

use App\Models\ShoppingListEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ShoppingListIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_add_an_entry_with_quantity(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('shopping-list.index')
            ->set('label', 'Milch')
            ->set('quantity', '2 Liter')
            ->call('add');

        $this->assertDatabaseHas('shopping_list_entries', [
            'household_id' => $household->id,
            'label' => 'Milch',
            'quantity' => '2 Liter',
            'checked' => false,
        ]);
    }

    public function test_a_member_can_toggle_and_delete_an_entry(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $entry = ShoppingListEntry::create([
            'household_id' => $household->id,
            'label' => 'Brot',
            'created_by' => $user->id,
        ]);

        Volt::test('shopping-list.index')->call('toggle', $entry->id);
        $this->assertDatabaseHas('shopping_list_entries', ['id' => $entry->id, 'checked' => true]);

        Volt::test('shopping-list.index')->call('delete', $entry->id);
        $this->assertDatabaseMissing('shopping_list_entries', ['id' => $entry->id]);
    }

    public function test_a_member_cannot_modify_another_households_entry(): void
    {
        [$user] = $this->memberWithHousehold();
        [$otherUser, $otherHousehold] = $this->memberWithHousehold();

        $foreignEntry = ShoppingListEntry::create([
            'household_id' => $otherHousehold->id,
            'label' => 'Fremdes Item',
            'created_by' => $otherUser->id,
        ]);

        $this->actingAs($user);

        $this->expectException(ModelNotFoundException::class);
        Volt::test('shopping-list.index')->call('delete', $foreignEntry->id);

        $this->assertDatabaseHas('shopping_list_entries', ['id' => $foreignEntry->id]);
    }
}
