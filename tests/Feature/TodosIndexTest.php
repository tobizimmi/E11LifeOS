<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TodosIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_add_a_todo(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('todos.index')
            ->set('title', 'Müll rausbringen')
            ->call('add');

        $this->assertDatabaseHas('items', [
            'household_id' => $household->id,
            'type' => Item::TYPE_TODO,
            'title' => 'Müll rausbringen',
            'done' => false,
        ]);
    }

    public function test_a_member_can_toggle_and_delete_a_todo(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $todo = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_TODO,
            'title' => 'Spülmaschine ausräumen',
            'created_by' => $user->id,
        ]);

        Volt::test('todos.index')->call('toggle', $todo->id);
        $this->assertDatabaseHas('items', ['id' => $todo->id, 'done' => true]);

        Volt::test('todos.index')->call('delete', $todo->id);
        $this->assertDatabaseMissing('items', ['id' => $todo->id]);
    }

    public function test_reordering_updates_positions_only_within_own_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $a = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_TODO, 'title' => 'A', 'created_by' => $user->id, 'position' => 0]);
        $b = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_TODO, 'title' => 'B', 'created_by' => $user->id, 'position' => 1]);

        [, $otherHousehold] = $this->memberWithHousehold();
        $foreign = Item::create(['household_id' => $otherHousehold->id, 'type' => Item::TYPE_TODO, 'title' => 'Foreign', 'created_by' => $user->id, 'position' => 0]);

        Volt::test('todos.index')->call('updateOrder', [$b->id, $a->id, $foreign->id]);

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(0, $foreign->fresh()->position);
    }
}
