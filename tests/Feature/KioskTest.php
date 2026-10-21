<?php

namespace Tests\Feature;

use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class KioskTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_kiosk_shows_open_shopping_items(): void
    {
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
        [$user, $household] = $this->memberWithHousehold();
        ShoppingListEntry::create(['household_id' => $household->id, 'label' => 'Milch', 'checked' => false]);
        ShoppingListEntry::create(['household_id' => $household->id, 'label' => 'Erledigt', 'checked' => true]);
        $this->actingAs($user);

        Volt::test('kiosk.index')
            ->assertSee(__('Today'))
            ->assertSee('Milch')
            ->assertDontSee('Erledigt');
    }

    public function test_the_kiosk_route_requires_login(): void
    {
        $this->get(route('kiosk.index'))->assertRedirect(route('login'));
    }
}
