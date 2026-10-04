<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\DashboardWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    public function test_deadlines_and_fixed_costs_are_collected_from_all_collections(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Verträge')->set('template', 'contracts')->call('create');
        $contracts = Collection::where('name', 'Verträge')->sole();
        $cost = $contracts->properties->firstWhere('name', 'Monatliche Kosten (€)');
        $cancelBy = $contracts->properties->firstWhere('name', 'Kündigen bis');

        foreach ([['Handy', 19.99, '2026-10-10'], ['Strom', 80.01, '2027-05-01']] as [$title, $amount, $date]) {
            $entry = Item::create(['household_id' => $household->id, 'collection_id' => $contracts->id, 'type' => Item::TYPE_ENTRY, 'title' => $title]);
            $entry->setPropertyValue($cost, $amount);
            $entry->setPropertyValue($cancelBy, $date);
            $entry->save();
        }

        $this->assertSame(['Handy'], array_map(fn (array $row) => $row['entry']->title, DashboardWidgets::deadlines($user, $household->id)));
        $this->assertSame(100.0, DashboardWidgets::monthlyCosts($user, $household->id)['total']);

        Volt::test('dashboard')
            ->call('loadWidgets')
            ->assertSee('Fristen (nächste 30 Tage)')
            ->assertSee('Handy')
            ->assertSee('in 15 Tagen')
            ->assertSee('100,00 €')
            ->assertSee('1.200,00 €');
    }

    public function test_widgets_can_be_hidden_reordered_and_reset(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('dashboard')
            ->set('customizing', true)
            ->call('toggleWidget', 'shopping')
            ->call('moveWidget', 'tasks', -1)
            ->assertDontSee('Nichts zu kaufen');

        $this->assertSame(['weather', 'deadlines', 'tasks', 'life', 'habits', 'meals', 'portfolio', 'real_estate', 'tax', 'freedom', 'costs', 'pages', 'inbox', 'energy', 'smart_home'], $user->fresh()->dashboard_widgets);

        Volt::test('dashboard')->call('toggleWidget', 'shopping')->call('resetWidgets');
        $this->assertNull($user->fresh()->dashboard_widgets);

        Volt::test('dashboard')->call('toggleWidget', 'unknown')->assertNotFound();
    }
}
