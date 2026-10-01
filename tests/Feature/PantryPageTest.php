<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\ShoppingListEntry;
use App\Support\Pantry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PantryPageTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_groceries_and_household_supplies_are_kept_in_stock_and_low_items_go_on_the_shopping_list(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:00'));
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('pantry.index'))->assertOk()->assertSee('Noch nichts im Vorrat.');
        $pantry = Pantry::collectionFor($household->id);
        $this->assertNotNull($pantry);

        Volt::test('pantry.index')
            ->call('create')
            ->set('entry.name', 'Toilettenpapier')->set('entry.kind', Pantry::KIND_SUPPLIES)->set('entry.quantity', '2')->set('entry.unit', 'Rollen')->set('entry.minimum', '4')
            ->call('save')->assertHasNoErrors()
            ->call('create')
            ->set('entry.name', 'Nudeln')->set('entry.quantity', '3')->set('entry.best_before', '2026-10-05')
            ->call('save')->assertHasNoErrors()
            // The same item again adds to its quantity.
            ->call('create')
            ->set('entry.name', 'Nudeln')->set('entry.quantity', '1,5')
            ->call('save')
            ->assertSee('1 Artikel geht zur Neige.')
            ->assertSee('Bald verbrauchen: Nudeln (05.10.)')
            ->set('kind', Pantry::KIND_SUPPLIES)->assertSee('Toilettenpapier')->assertDontSeeHtml('aria-label="Menge: Nudeln"');

        $stock = Pantry::inventory($user, $household->id)->keyBy('name');
        $this->assertSame([Pantry::KIND_SUPPLIES, 2.0, 'rollen', true], [$stock['Toilettenpapier']['kind'], $stock['Toilettenpapier']['quantity'], mb_strtolower($stock['Toilettenpapier']['unit']), $stock['Toilettenpapier']['low']]);
        $this->assertSame([Pantry::KIND_FOOD, 4.5, false], [$stock['Nudeln']['kind'], $stock['Nudeln']['quantity'], $stock['Nudeln']['low']]);

        $component = Volt::test('pantry.index')
            ->call('adjust', $stock['Nudeln']['item']->id, -1)
            ->call('toShoppingList');
        $this->assertSame(3.5, Pantry::inventory($user, $household->id)->firstWhere('name', 'Nudeln')['quantity']);
        $this->assertSame(['Toilettenpapier'], ShoppingListEntry::pluck('label')->all());
        $this->assertSame('3 Rollen', ShoppingListEntry::sole()->quantity);

        // Never below zero, and only the own household's stock.
        $component->call('adjust', $stock['Toilettenpapier']['item']->id, -1)->call('adjust', $stock['Toilettenpapier']['item']->id, -1)->call('adjust', $stock['Toilettenpapier']['item']->id, -1);
        $this->assertSame(0.0, Pantry::inventory($user, $household->id)->firstWhere('name', 'Toilettenpapier')['quantity']);

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('pantry.index')->call('adjust', $stock['Nudeln']['item']->id, 1)->assertNotFound();
    }

    public function test_an_existing_pantry_collection_gets_the_kind_field(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::create(['household_id' => $household->id, 'name' => 'Vorräte', 'visibility' => 'shared', 'created_by' => $user->id]);
        $collection->properties()->create(['name' => 'Menge', 'type' => 'number', 'position' => 0]);
        $collection->properties()->create(['name' => 'MHD', 'type' => 'date', 'position' => 1]);

        $pantry = Pantry::ensure($user, $household->id);

        $this->assertSame($collection->id, $pantry->id);
        $this->assertSame([Pantry::KIND_FOOD, Pantry::KIND_SUPPLIES], array_column($pantry->systemProperty('kind')->selectOptions(), 'id'));
        $this->assertSame(1, Collection::count());
    }
}
