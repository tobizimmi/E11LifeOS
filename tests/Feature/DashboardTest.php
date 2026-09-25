<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_dashboard_summarizes_the_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        Item::factory()->todo()->for($household)->create(['title' => 'Fenster putzen']);
        Item::factory()->todo()->for($household)->create(['title' => 'Schon erledigt', 'done' => true]);
        Item::factory()->note()->for($household)->create(['title' => 'Packliste Urlaub']);
        Item::factory()->inbox()->for($household)->create(['title' => 'Arzttermin ausmachen']);
        ShoppingListEntry::factory()->for($household)->create(['label' => 'Kaffee']);
        ShoppingListEntry::factory()->for($household)->create(['label' => 'Schon gekauft', 'checked' => true]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeVolt('quick-capture')
            ->assertSee(['Fenster putzen', 'Packliste Urlaub', 'Arzttermin ausmachen', 'Kaffee'])
            ->assertDontSee(['Schon erledigt', 'Schon gekauft']);
    }

    public function test_the_dashboard_hides_other_members_private_items(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        Item::factory()->todo()->for($household)->privateTo($owner)->create(['title' => 'Überraschungsparty planen']);

        $this->actingAs($partner);

        Volt::test('dashboard')
            ->assertViewHas('openTodoCount', 0)
            ->assertDontSee('Überraschungsparty planen');
    }

    public function test_tasks_can_be_completed_from_the_dashboard(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $todo = Item::factory()->todo()->for($household)->create();

        $this->actingAs($user);

        Volt::test('dashboard')->call('toggleTodo', $todo->id);

        $this->assertTrue($todo->fresh()->done);
    }

    public function test_users_without_a_household_see_a_hint(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('You are not a member of a household yet. Ask a household member for an invite code.'));
    }
}
